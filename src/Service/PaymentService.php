<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Cart;
use App\Entity\Notification;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\ServicePayment;
use App\Entity\Subscription;
use App\Entity\Tariff;
use App\Notification\AdminNotifier;
use App\Notification\NotificationDispatcher;
use App\Payment\Gateway\PaymentGatewayRegistry;
use App\Payment\Gateway\PaymentStatusResult;
use App\Repository\SellerLegalEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use YooKassa\Client;
use YooKassa\Model\Notification\NotificationEventType;
use YooKassa\Model\Notification\NotificationFactory;

readonly class PaymentService
{
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
        private AdminNotifier $admin,
        private LoggerInterface $logger,
        private YooKassaClientFactory $clientFactory,
        private PaymentGatewayRegistry $gateways,
        private SellerLegalEntityRepository $legalEntities,
        private string $shopId,
        private string $secretKey,
    ) {}

    /** Настроены ли платформенные реквизиты (для подписок). */
    public function isConfigured(): bool
    {
        return $this->shopId !== '' && $this->secretKey !== '';
    }

    /** Клиент платформы — для платежей подписок (доход платформы). */
    private function platformClient(): Client
    {
        return $this->clientFactory->make($this->shopId, $this->secretKey);
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

    // ── Subscription payments (платформенные креды) ─────────

    /**
     * Создаёт платёж за переход на выбранный (целевой) тариф.
     * Цена берётся из $tariff, а не из текущего тарифа подписки.
     */
    public function createSubscriptionPayment(Subscription $subscription, Tariff $tariff, string $returnUrl, string $description): ?string
    {
        if ((float) $tariff->getPriceRub() <= 0) {
            return null;
        }

        $payment = new Payment();
        $payment->setSubscription($subscription);
        $payment->setAmount($tariff->getPriceRub());
        $payment->setCurrency('RUB');

        try {
            $idempotenceKey = sprintf('sub-%d-tariff-%d-%s', $subscription->getId(), $tariff->getId(), $subscription->getCurrentPeriodStart()->format('Y-m-d'));
            $response = $this->platformClient()->createPayment([
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
                    'tariff_id'       => $tariff->getId(),
                    'payment_type'    => 'subscription',
                ],
            ], $idempotenceKey);

            $payment->setGatewayPaymentId($response->getId());
            $payment->setPaymentMethod('card_online');
            $this->em->persist($payment);
            $this->em->flush();

            return $response->getConfirmation()->getConfirmationUrl();
        } catch (\Throwable $e) {
            $this->logger->error('YooKassa: ошибка создания платежа подписки', ['exception' => $e]);
            return null;
        }
    }

    // ── Service payments (разовые услуги, платформенные креды) ──

    /**
     * Разовая оплата платной услуги (напр. «Размещение под ключ» 5 000₽) — тот же
     * платформенный YooKassa-клиент, что и подписки (доход площадки, не бренда).
     */
    public function createServicePayment(ServicePayment $servicePayment, string $returnUrl, string $description): ?string
    {
        try {
            $idempotenceKey = sprintf('service-%d', $servicePayment->getId());
            $response = $this->platformClient()->createPayment([
                'amount' => [
                    'value'    => $servicePayment->getAmount(),
                    'currency' => 'RUB',
                ],
                'confirmation' => [
                    'type'      => 'redirect',
                    'return_url' => $returnUrl,
                ],
                'capture' => true,
                'description' => $description,
                'metadata' => [
                    'service_payment_id' => $servicePayment->getId(),
                    'payment_type'       => 'service',
                ],
            ], $idempotenceKey);

            $servicePayment->setYookassaPaymentId($response->getId());
            $this->em->flush();

            return $response->getConfirmation()->getConfirmationUrl();
        } catch (\Throwable $e) {
            $this->logger->error('YooKassa: ошибка создания платежа услуги', ['exception' => $e]);
            return null;
        }
    }

    // ── Order payments (через счёт бренда) ──────────────────

    /**
     * Деньги за заказ уходят напрямую бренду — через его основной счёт приёма.
     * Заказы одного чекаута должны быть одного бренда (один шлюз на платёж).
     *
     * @param Order[] $orders
     */
    public function createOrderPayment(array $orders, string $returnUrl): ?string
    {
        if ($orders === []) {
            return null;
        }

        $brand = $orders[0]->getBrand();
        foreach ($orders as $order) {
            if ($order->getBrand()?->getId() !== $brand?->getId()) {
                $this->logger->error('Оплата заказа: заказы разных брендов нельзя провести одним платежом');
                return null;
            }
        }
        if ($brand === null) {
            return null;
        }

        $legalEntity = $this->legalEntities->findActiveForBrand($brand);
        $account = $legalEntity?->getReadyPrimaryAccount();
        if ($legalEntity === null || $account === null) {
            $this->logger->warning('Оплата заказа: у бренда не настроен готовый счёт приёма оплаты', ['brand_id' => $brand->getId()]);
            return null;
        }

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
            $gateway = $this->gateways->get((string) $account->getProvider()?->getCode());
            $result = $gateway->createRedirectPayment(
                $account,
                number_format($total, 2, '.', ''),
                'RUB',
                sprintf('Заказы %s', $orderNumbersStr),
                $returnUrl,
                ['order_numbers' => $orderNumbersStr, 'payment_type' => 'order'],
                sprintf('orders-%s', $orderNumbersStr),
            );

            $gatewayId = $result->gatewayPaymentId;
            foreach ($orders as $order) {
                $order->setGatewayPaymentId($gatewayId);
                $order->setSellerLegalEntity($legalEntity);
                $order->setSellerPaymentAccount($account);
            }
            $payment->setGatewayPaymentId($gatewayId);
            $payment->setPaymentMethod('card_online');
            $this->em->persist($payment);
            $this->em->flush();

            return $result->confirmationUrl;
        } catch (\Throwable $e) {
            $this->logger->error('Платёж заказа: ошибка создания', ['exception' => $e]);
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

            $event = $notification->getEvent();
            $metadata = $object->getMetadata() ?? [];
            $paymentType = $metadata['payment_type'] ?? null;

            // Refund — без обращения к API, по локальной записи Payment
            if ($event === NotificationEventType::PAYMENT_REFUNDED) {
                return $this->handleRefund($bodyPaymentId);
            }

            if ($paymentType === 'subscription') {
                // Авторитетный статус — через платформенный клиент
                $info = $this->platformClient()->getPaymentInfo($bodyPaymentId);
                return $this->handleSubscriptionPayment($bodyPaymentId, $info->getStatus(), $metadata, $info->getAmount());
            }

            if ($paymentType === 'order') {
                // Авторитетный статус — через клиент счёта бренда (см. handleOrderPayment)
                return $this->handleOrderPayment($bodyPaymentId, $metadata);
            }

            if ($paymentType === 'service') {
                // Авторитетный статус — через платформенный клиент (тот же путь, что подписки)
                $info = $this->platformClient()->getPaymentInfo($bodyPaymentId);
                return $this->handleServicePayment($bodyPaymentId, $info->getStatus(), $metadata, $info->getAmount());
            }

            return false;
        } catch (\Throwable $e) {
            $this->logger->error('YooKassa: ошибка обработки webhook', ['exception' => $e]);
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

    private function handleSubscriptionPayment(string $paymentId, string $status, array $metadata, object $confirmedAmount): bool
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
                // Апгрейд на оплаченный тариф
                $tariffId = $metadata['tariff_id'] ?? null;
                if ($tariffId) {
                    $tariff = $this->em->getRepository(Tariff::class)->find((int) $tariffId);
                    if ($tariff) {
                        $subscription->setTariff($tariff);
                    }
                }
                $subscription->setCurrentPeriodStart($now);
                $subscription->setCurrentPeriodEnd($now->modify('+1 month'));
                $subscription->setStatus(Subscription::STATUS_ACTIVE);
            }
        } elseif (in_array($status, ['canceled', 'failed'], true)) {
            if ($payment->getStatus() === Payment::STATUS_PAID) {
                return true;
            }
            $payment->markAsFailed();
        }

        $this->em->flush();

        if ($status === 'succeeded' && $payment->getStatus() === Payment::STATUS_PAID) {
            $sub = $payment->getSubscription();
            $this->admin->send(sprintf(
                "💰 <b>Оплата подписки</b>\nБренд «%s», тариф %s\nСумма: %s ₽",
                htmlspecialchars((string) $sub?->getBrand()?->getTitle(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $sub?->getTariff()?->getCode(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($payment->getAmount(), ENT_QUOTES, 'UTF-8'),
            ));
        }

        return true;
    }

    private function handleServicePayment(string $paymentId, string $status, array $metadata, object $confirmedAmount): bool
    {
        $servicePaymentId = $metadata['service_payment_id'] ?? null;
        if (!$servicePaymentId) {
            return false;
        }

        $servicePayment = $this->em->getRepository(ServicePayment::class)->find((int) $servicePaymentId);
        if (!$servicePayment || $servicePayment->getYookassaPaymentId() !== $paymentId) {
            return false;
        }

        // Проверяем сумму/валюту
        if ($confirmedAmount->getValue() !== $servicePayment->getAmount()) {
            return false;
        }
        if ((string) $confirmedAmount->getCurrency() !== 'RUB') {
            return false;
        }

        if ($status === 'succeeded') {
            if ($servicePayment->getStatus() === ServicePayment::STATUS_SUCCEEDED) {
                return true;
            }
            $servicePayment->markAsSucceeded($paymentId);
        } elseif (in_array($status, ['canceled', 'failed'], true)) {
            if ($servicePayment->getStatus() === ServicePayment::STATUS_SUCCEEDED) {
                return true;
            }
            $servicePayment->markAsCanceled();
        }

        $this->em->flush();

        if ($status === 'succeeded' && $servicePayment->getStatus() === ServicePayment::STATUS_SUCCEEDED) {
            $this->admin->send(sprintf(
                "💰 <b>ОПЛАТА УСЛУГИ 5000₽</b> от %s\nУслуга: %s\nБренд/подсказка: %s",
                htmlspecialchars($servicePayment->getEmail(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($servicePayment->getServiceCode(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($servicePayment->getBrandHint() ?? '—', ENT_QUOTES, 'UTF-8'),
            ));
        }

        return true;
    }

    private function handleOrderPayment(string $paymentId, array $metadata): bool
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

        // Авторитетный статус — через шлюз того счёта, на который шёл платёж.
        // Снимок сделан при создании платежа; для legacy-заказов — платформенный клиент.
        $account = $orders[0]->getSellerPaymentAccount();
        if ($account !== null) {
            $st = $this->gateways->get((string) $account->getProvider()?->getCode())->fetchStatus($account, $paymentId);
        } else {
            $info = $this->platformClient()->getPaymentInfo($paymentId);
            $amount = $info->getAmount();
            $st = new PaymentStatusResult($info->getStatus(), $amount->getValue(), (string) $amount->getCurrency());
        }
        $status = $st->status;

        // Проверяем сумму/валюту
        if ($st->amountValue !== $payment->getAmount()) {
            return false;
        }
        if ($st->currency !== (string) $payment->getCurrency()) {
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

        if ($status === 'succeeded' && $payment->getStatus() === Payment::STATUS_PAID) {
            $this->admin->send(sprintf(
                "💰 <b>Оплата заказа(ов)</b> %s\nСумма: %s ₽",
                htmlspecialchars((string) $orderNumbersStr, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($payment->getAmount(), ENT_QUOTES, 'UTF-8'),
            ));
        }

        return true;
    }
}
