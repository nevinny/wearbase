<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Cart;
use App\Entity\Notification;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Subscription;
use App\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use YooKassa\Client;
use YooKassa\Model\Notification\NotificationEventType;
use YooKassa\Model\Notification\NotificationFactory;

readonly class PaymentService
{
    private Client $client;

    private const YOO_IPS = [
        '185.71.76.0/27',
        '185.71.77.0/27',
        '77.75.153.0/27',
        '77.75.156.0/27',
        '2a02:5180:0:1509::/64',
        '2a02:5180:0:2655::/64',
        '2a02:5180:0:1533::/64',
        '2a02:5180:0:2669::/64',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private NotificationDispatcher $notifier,
        string $shopId,
        string $secretKey,
    ) {
        $this->client = new Client();
        $this->client->setAuth($shopId, $secretKey);
    }

    public static function isYooIp(string $ip): bool
    {
        foreach (self::YOO_IPS as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $mask = -1 << (32 - $bits);
            return ($ipLong & $mask) === ($subnetLong & $mask);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) {
                return false;
            }
            $mask = str_repeat("\xff", $bits >> 3);
            $remaining = $bits % 8;
            if ($remaining) {
                $mask .= chr(0xff << (8 - $remaining));
            }
            $mask = str_pad($mask, 16, "\x00");
            return ($ipBin & $mask) === ($subnetBin & $mask);
        }

        return false;
    }

    // ── Subscription payments ───────────────────────────────

    public function createSubscriptionPayment(Subscription $subscription, string $returnUrl, string $description): ?string
    {
        $tariff = $subscription->getTariff();
        if (!$tariff || (float) $tariff->getPriceRub() <= 0) {
            return null;
        }

        $brand = $subscription->getBrand();
        $payment = new Payment();
        $payment->setSubscription($subscription);
        $payment->setAmount($tariff->getPriceRub());
        $payment->setCurrency('RUB');

        try {
            $idempotenceKey = sprintf('sub-%d-%s', $subscription->getId(), $subscription->getCurrentPeriodStart()->format('Y-m-d'));
            $response = $this->client->createPayment([
                'amount' => [
                    'value'    => $tariff->getPriceRub(),
                    'currency' => 'RUB',
                ],
                'confirmation' => [
                    'type'      => 'redirect',
                    'return_url' => $returnUrl,
                ],
                'capture' => true,
                'description' => $description,
                'metadata' => [
                    'subscription_id' => $subscription->getId(),
                    'payment_type'    => 'subscription',
                ],
            ], $idempotenceKey);

            $payment->setGatewayPaymentId($response->getId());
            $payment->setPaymentMethod('card_online');
            $this->em->persist($payment);
            $this->em->flush();

            return $response->getConfirmation()->getConfirmationUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Order payments ──────────────────────────────────────

    /**
     * @param Order[] $orders
     */
    public function createOrderPayment(array $orders, string $returnUrl): ?string
    {
        $total = 0.0;
        $orderNumbers = [];
        foreach ($orders as $order) {
            $total += (float) $order->getTotalAmount();
            $orderNumbers[] = $order->getOrderNumber();
        }

        if ($total <= 0) {
            return null;
        }

        $orderNumbersStr = implode(',', $orderNumbers);

        $payment = new Payment();
        $payment->setAmount((string) $total);
        $payment->setCurrency('RUB');

        try {
            $idempotenceKey = sprintf('orders-%s', $orderNumbersStr);
            $response = $this->client->createPayment([
                'amount' => [
                    'value'    => number_format($total, 2, '.', ''),
                    'currency' => 'RUB',
                ],
                'confirmation' => [
                    'type'      => 'redirect',
                    'return_url' => $returnUrl,
                ],
                'capture' => true,
                'description' => sprintf('Заказы %s', $orderNumbersStr),
                'metadata' => [
                    'order_numbers' => $orderNumbersStr,
                    'payment_type'  => 'order',
                ],
            ], $idempotenceKey);

            $gatewayId = $response->getId();
            foreach ($orders as $order) {
                $order->setGatewayPaymentId($gatewayId);
            }
            $payment->setGatewayPaymentId($gatewayId);
            $payment->setPaymentMethod('card_online');
            $this->em->persist($payment);
            $this->em->flush();

            return $response->getConfirmation()->getConfirmationUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Webhook handler ────────────────────────────────────

    public function handleNotification(string $json): bool
    {
        try {
            $factory = new NotificationFactory();
            $notification = $factory->factory($json);
            $object = $notification->getObject();
            $bodyPaymentId = $object->getId();

            // Перезапрашиваем авторитетный статус через API
            $paymentInfo = $this->client->getPaymentInfo($bodyPaymentId);
            $status = $paymentInfo->getStatus();
            $confirmedAmount = $paymentInfo->getAmount();

            $event = $notification->getEvent();
            $metadata = $object->getMetadata() ?? [];
            $paymentType = $metadata['payment_type'] ?? null;

            // Refund
            if ($event === NotificationEventType::PAYMENT_REFUNDED) {
                return $this->handleRefund($bodyPaymentId);
            }

            if ($paymentType === 'subscription') {
                return $this->handleSubscriptionPayment($bodyPaymentId, $status, $confirmedAmount);
            }

            if ($paymentType === 'order') {
                return $this->handleOrderPayment($bodyPaymentId, $status, $metadata, $confirmedAmount);
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function handleRefund(string $paymentId): bool
    {
        $payment = $this->em->getRepository(Payment::class)->findOneBy(['gatewayPaymentId' => $paymentId]);
        if (!$payment) {
            return false;
        }

        if ($payment->getStatus() === Payment::STATUS_PAID) {
            $payment->setStatus(Payment::STATUS_REFUNDED);
            $this->em->flush();
        }

        return true;
    }

    private function handleSubscriptionPayment(string $paymentId, string $status, object $confirmedAmount): bool
    {
        $payment = $this->em->getRepository(Payment::class)->findOneBy(['gatewayPaymentId' => $paymentId]);
        if (!$payment) {
            return false;
        }

        // Проверяем сумму/валюту
        $expectedAmount = $payment->getAmount();
        $actualAmount = $confirmedAmount->getValue();
        if ($actualAmount !== $expectedAmount) {
            return false;
        }
        if ((string) $confirmedAmount->getCurrency() !== (string) $payment->getCurrency()) {
            return false;
        }

        if ($status === 'succeeded') {
            if ($payment->getStatus() === Payment::STATUS_PAID) {
                return true;
            }
            $payment->markAsPaid($paymentId);
            $subscription = $payment->getSubscription();
            if ($subscription) {
                $now = new \DateTimeImmutable();
                $subscription->setCurrentPeriodStart($now);
                $subscription->setCurrentPeriodEnd($now->modify('+1 month'));
                if ($subscription->isOnTrial()) {
                    $subscription->setStatus(Subscription::STATUS_ACTIVE);
                }
            }
        } elseif (in_array($status, ['canceled', 'failed'], true)) {
            if ($payment->getStatus() === Payment::STATUS_PAID) {
                return true;
            }
            $payment->markAsFailed();
        }

        $this->em->flush();
        return true;
    }

    private function handleOrderPayment(string $paymentId, string $status, array $metadata, object $confirmedAmount): bool
    {
        $orderNumbersStr = $metadata['order_numbers'] ?? ($metadata['order_number'] ?? null);
        if (!$orderNumbersStr) {
            return false;
        }

        $orderNumbers = explode(',', (string) $orderNumbersStr);
        $orders = $this->em->getRepository(Order::class)->findBy(['orderNumber' => $orderNumbers]);
        if ($orders === []) {
            return false;
        }

        $payment = $this->em->getRepository(Payment::class)->findOneBy(['gatewayPaymentId' => $paymentId]);
        if (!$payment) {
            return false;
        }

        // Проверяем сумму/валюту
        $expectedAmount = $payment->getAmount();
        $actualAmount = $confirmedAmount->getValue();
        if ($actualAmount !== $expectedAmount) {
            return false;
        }
        if ((string) $confirmedAmount->getCurrency() !== (string) $payment->getCurrency()) {
            return false;
        }

        if ($status === 'succeeded') {
            if ($payment->getStatus() === Payment::STATUS_PAID) {
                return true;
            }
            $payment->markAsPaid($paymentId);
            foreach ($orders as $order) {
                $order->setPaymentStatus(Order::PAYMENT_PAID);
                $user = $order->getCustomer();
                if ($user) {
                    $cart = $this->em->getRepository(Cart::class)->findOneBy(['user' => $user]);
                    if ($cart) {
                        foreach ($cart->getItems() as $item) {
                            $this->em->remove($item);
                        }
                    }
                    $this->notifier->dispatch(
                        $user,
                        Notification::TYPE_ORDER_STATUS,
                        "Заказ #{$order->getOrderNumber()} оплачен",
                        "Оплата заказа на сумму {$order->getTotalAmount()} руб. прошла успешно.",
                        ['order_id' => $order->getId(), 'order_number' => $order->getOrderNumber()],
                        'order_status_changed',
                        ['order' => $order],
                    );
                }
            }
        } elseif (in_array($status, ['canceled', 'failed'], true)) {
            if ($payment->getStatus() === Payment::STATUS_PAID) {
                return true;
            }
            $payment->markAsFailed();
            foreach ($orders as $order) {
                $order->setPaymentStatus(Order::PAYMENT_FAILED);
                $user = $order->getCustomer();
                if ($user) {
                    $this->notifier->dispatch(
                        $user,
                        Notification::TYPE_ORDER_STATUS,
                        "Оплата заказа #{$order->getOrderNumber()} не прошла",
                        "Не удалось провести оплату заказа. Попробуйте ещё раз.",
                        ['order_id' => $order->getId(), 'order_number' => $order->getOrderNumber()],
                    );
                }
            }
        }

        $this->em->flush();
        return true;
    }
}
