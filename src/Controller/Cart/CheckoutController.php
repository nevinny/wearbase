<?php

declare(strict_types=1);

namespace App\Controller\Cart;

use App\Entity\Notification;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\OrderStatusHistory;
use App\Notification\NotificationDispatcher;
use App\Repository\BrandUserRepository;
use App\Repository\CartRepository;
use App\Repository\CountryRepository;
use App\Repository\ShippingRuleRepository;
use App\Service\DeliveryService;
use App\Service\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/checkout', name: 'checkout_')]
class CheckoutController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(
        CartRepository       $cartRepo,
        CountryRepository    $countryRepo,
        ShippingRuleRepository $shippingRepo,
        Request              $request,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $cart = $cartRepo->findOneBy(['user' => $user]);

        if (!$cart || $cart->isEmpty()) {
            return $this->redirectToRoute('cart_index');
        }

        // Страна по умолчанию — Россия
        $defaultCountry = $countryRepo->findByCode('RU');
        $countries      = $countryRepo->findActive();

        // Правила доставки для страны по умолчанию
        $shippingRules = $defaultCountry
            ? $shippingRepo->findForCountry($defaultCountry)
            : [];

        return $this->render('checkout/index.html.twig', [
            'cart'          => $cart,
            'groups'        => $cart->groupByBrand(),
            'addresses'     => $user->getAddresses(),
            'default'       => $user->getDefaultAddress(),
            'countries'     => $countries,
            'shippingRules' => $shippingRules,
            'cartWeight'    => $this->calculateCartWeight($cart),
        ]);
    }

    /**
     * AJAX: получить правила доставки для страны.
     * GET /checkout/shipping-rules?country=DE
     */
    #[Route('/shipping-rules', name: 'shipping_rules', methods: ['GET'])]
    public function shippingRules(
        Request                $request,
        CountryRepository      $countryRepo,
        ShippingRuleRepository $shippingRepo,
        DeliveryService        $delivery,
    ): JsonResponse {
        $code    = strtoupper((string) $request->query->get('country', 'RU'));
        $country = $countryRepo->findByCode($code);

        if (!$country) {
            return $this->json(['rules' => [], 'error' => 'Country not found']);
        }

        $toCity   = (string) $request->query->get('city', 'Москва');
        $weightKg = (float) $request->query->get('weight', 1);

        $rules   = $shippingRepo->findForCountry($country);
        $data    = [];
        $hasLive = false;
        foreach ($rules as $rule) {
            // Исключаем правила с превышением веса
            if ($rule->getMaxWeightKg() !== null && $weightKg > (float) $rule->getMaxWeightKg()) {
                continue;
            }

            $live = $delivery->calculate(
                $rule->getCarrier(),
                $country,
                'Москва',
                $toCity,
                $weightKg,
            );
            if ($live) $hasLive = true;

            $data[] = [
                'id'         => $rule->getId(),
                'carrier'    => $rule->getCarrier(),
                'name'       => $rule->getName(),
                'priceRub'   => $live['price'] ?? (float) $rule->getPriceRub(),
                'daysMin'    => $live['daysMin'] ?? $rule->getDaysMin(),
                'daysMax'    => $live['daysMax'] ?? $rule->getDaysMax(),
                'freeFromRub'=> $rule->getFreeFromRub() ? (float) $rule->getFreeFromRub() : null,
                'label'      => $live ? sprintf('%d–%d дней', $live['daysMin'], $live['daysMax']) : $rule->getDeliveryLabel(),
                'trackingUrl'=> $rule->getTrackingUrl(),
                'is_live'    => $live !== null,
            ];
        }

        return $this->json(['rules' => $data, 'has_live' => $hasLive]);
    }

    #[Route('/confirm', name: 'confirm', methods: ['POST'])]
    public function confirm(
        Request                $request,
        CartRepository         $cartRepo,
        CountryRepository      $countryRepo,
        ShippingRuleRepository $shippingRepo,
        EntityManagerInterface $em,
        BrandUserRepository    $brandUserRepo,
        NotificationDispatcher $notifier,
        PaymentService         $paymentService,
        DeliveryService        $delivery,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $cart = $cartRepo->findOneBy(['user' => $user]);

        if (!$cart || $cart->isEmpty()) {
            return $this->redirectToRoute('cart_index');
        }

        if (!$this->isCsrfTokenValid('checkout_confirm', $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('cart_index');
        }

        if (!$user->isEmailVerified()) {
            $this->addFlash('error', 'Подтвердите email перед оформлением заказа');
            return $this->redirectToRoute('account_security');
        }

        $addressData    = $this->buildAddressData($request, $user);
        $deliveryMethod = $request->request->get('delivery_method', 'cdek');
        $shippingRuleId = (int) $request->request->get('shipping_rule_id', 0);
        $paymentMethod  = $request->request->get('payment_method', 'card_online');
        $customerNote   = $request->request->get('note');
        $countryCode    = strtoupper((string) $request->request->get('country', 'RU'));

        // Определяем стоимость доставки из API (или static fallback)
        $shippingCost = $this->resolveShippingCost(
            $shippingRuleId,
            $deliveryMethod,
            $countryCode,
            $countryRepo,
            $shippingRepo,
            $delivery,
            $addressData,
            $cart,
        );

        $createdOrders = [];
        $redirectResponse = null;

        $em->wrapInTransaction(function (EntityManagerInterface $em) use (
            $cart,
            $user,
            $addressData,
            $deliveryMethod,
            $shippingRuleId,
            $paymentMethod,
            $customerNote,
            $shippingCost,
            $shippingRepo,
            $brandUserRepo,
            $notifier,
            $paymentService,
            &$createdOrders,
            &$redirectResponse,
        ) {
            // Один заказ на каждый бренд
            foreach ($cart->groupByBrand() as ['brand' => $brand, 'items' => $items]) {
                $order = new Order();
                $order->setOrderNumber($this->generateOrderNumber($em));
                $order->setCustomer($user);
                $order->setBrand($brand);
                $order->setShippingAddress($addressData);
                $order->setDeliveryMethod($deliveryMethod);
                $order->setPaymentMethod($paymentMethod);
                $order->setCustomerNote($customerNote);

                $subtotal = 0.0;
                foreach ($items as $cartItem) {
                    $variant = $cartItem->getVariant();
                    $requestedQty = $cartItem->getQty();

                    if ($variant->getStockQty() < $requestedQty) {
                        $this->addFlash('error', sprintf('Недостаточно товара "%s" на складе', $variant->getTitle()));
                        $redirectResponse = $this->redirectToRoute('cart_index');
                        return;
                    }

                    $orderItem = new OrderItem();
                    $orderItem->fillFromVariant($variant, $requestedQty);
                    $order->addOrderItem($orderItem);
                    $em->persist($orderItem);
                    $subtotal += (float) $orderItem->getTotal();

                    $variant->setStockQty($variant->getStockQty() - $requestedQty);
                }

                // Бесплатная доставка от порога
                $effectiveShipping = $shippingCost;
                $rule = $shippingRuleId ? $shippingRepo->find($shippingRuleId) : null;
                if ($rule && $rule->getFreeFromRub() !== null && $subtotal >= (float) $rule->getFreeFromRub()) {
                    $effectiveShipping = 0.0;
                }

                $order->setSubtotal((string) round($subtotal, 2));
                $order->setShippingCost((string) $effectiveShipping);
                $order->setTotalAmount((string) round($subtotal + $effectiveShipping, 2));

                // История статусов
                $history = new OrderStatusHistory();
                $history->setOrder($order);
                $history->setToStatus(Order::STATUS_NEW);
                $history->setComment('Заказ создан покупателем');
                $em->persist($history);

                $em->persist($order);
                $createdOrders[] = $order;

                // Notify brand managers
                $brandUsers = $brandUserRepo->findBy(['brand' => $brand]);
                foreach ($brandUsers as $bu) {
                    $manager = $bu->getUser();
                    if ($manager === $user) continue;

                    $notifier->dispatch(
                        $manager,
                        Notification::TYPE_ORDER_NEW,
                        "Новый заказ #{$order->getOrderNumber()}",
                        "Поступил заказ на сумму {$order->getTotalAmount()} руб.",
                        ['order_id' => $order->getId(), 'order_number' => $order->getOrderNumber()],
                        'new_order_brand',
                        ['order' => $order],
                    );
                }
            }

            // Notify buyer
            foreach ($createdOrders as $createdOrder) {
                $notifier->dispatch(
                    $user,
                    Notification::TYPE_ORDER_NEW,
                    "Заказ #{$createdOrder->getOrderNumber()} оформлен",
                    "Заказ на сумму {$createdOrder->getTotalAmount()} руб. принят.",
                    ['order_id' => $createdOrder->getId(), 'order_number' => $createdOrder->getOrderNumber()],
                    'order_confirmation',
                    ['order' => $createdOrder],
                );
            }

            $em->flush();

            // Если оплата картой — редирект на ЮKassa
            if ($paymentMethod === 'card_online' && $createdOrders !== []) {
                $returnUrl = $this->generateUrl('account_orders', [], UrlGeneratorInterface::ABSOLUTE_URL);
                $paymentUrl = $paymentService->createOrderPayment($createdOrders, $returnUrl);
                if ($paymentUrl) {
                    foreach ($cart->getItems() as $item) {
                        $em->remove($item);
                    }
                    $em->flush();
                    $redirectResponse = $this->redirect($paymentUrl);
                    return;
                }
            }

            if (count($createdOrders) === 1) {
                $redirectResponse = $this->redirectToRoute('checkout_success', [
                    'number' => $createdOrders[0]->getOrderNumber(),
                ]);
            } else {
                $redirectResponse = $this->redirectToRoute('checkout_success_multi');
            }
        });

        if ($redirectResponse) {
            return $redirectResponse;
        }

        return $this->redirectToRoute('cart_index');
    }

    #[Route('/success/{number}', name: 'success')]
    public function success(
        string $number,
        CartRepository $cartRepo,
        EntityManagerInterface $em,
    ): Response {
        $cart = $this->getUser() ? $cartRepo->findOneBy(['user' => $this->getUser()]) : null;
        if ($cart) {
            foreach ($cart->getItems() as $item) {
                $em->remove($item);
            }
            $em->flush();
        }
        return $this->render('checkout/success.html.twig', [
            'order_number' => $number,
        ]);
    }

    #[Route('/success', name: 'success_multi')]
    public function successMulti(
        CartRepository $cartRepo,
        EntityManagerInterface $em,
    ): Response {
        $cart = $this->getUser() ? $cartRepo->findOneBy(['user' => $this->getUser()]) : null;
        if ($cart) {
            foreach ($cart->getItems() as $item) {
                $em->remove($item);
            }
            $em->flush();
        }
        return $this->render('checkout/success.html.twig', [
            'order_number' => null,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveShippingCost(
        int                    $ruleId,
        string                 $carrier,
        string                 $countryCode,
        CountryRepository      $countryRepo,
        ShippingRuleRepository $shippingRepo,
        DeliveryService        $delivery,
        array                  $addressData,
        \App\Entity\Cart       $cart,
    ): float {
        $country = $countryRepo->findByCode($countryCode);
        $toCity  = $addressData['city'] ?? 'Москва';
        $weight  = $this->getCartWeight($cart);

        // Приоритет 1: live API
        if ($country) {
            $live = $delivery->calculate($carrier, $country, 'Москва', $toCity, $weight);
            if ($live !== null) {
                return $live['price'];
            }
        }

        // Приоритет 2: static rule по ID
        if ($ruleId > 0) {
            $rule = $shippingRepo->find($ruleId);
            if ($rule && $rule->isActive()) {
                return (float) $rule->getPriceRub();
            }
        }

        // Приоритет 3: первое правило для перевозчика и страны
        if ($country) {
            $rules = $shippingRepo->findForCountry($country);
            foreach ($rules as $rule) {
                if ($rule->getCarrier() === $carrier) {
                    return (float) $rule->getPriceRub();
                }
            }
            // Дешевейшее для страны
            $cheapest = $shippingRepo->findCheapestForCountry($country);
            if ($cheapest) {
                return (float) $cheapest->getPriceRub();
            }
        }

        // Fallback
        return match ($carrier) {
            'pickup'   => 0.0,
            'cdek'     => 350.0,
            'boxberry' => 280.0,
            'pochta'   => 200.0,
            default    => 350.0,
        };
    }

    private function getCartWeight(\App\Entity\Cart $cart): float
    {
        $weight = 0.0;
        foreach ($cart->getItems() as $item) {
            $v = $item->getVariant();
            if ($v && $v->getWeight()) {
                $weight += (float) $v->getWeight() * $item->getQty() / 1000; // grams → kg
            } else {
                $weight += 0.3 * $item->getQty(); // default 300g per item
            }
        }
        return max(0.5, $weight);
    }

    private function generateOrderNumber(EntityManagerInterface $em): string
    {
        $maxAttempts = 10;
        do {
            $number = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $exists = $em->getRepository(Order::class)->findOneBy(['orderNumber' => $number]);
            $maxAttempts--;
        } while ($exists && $maxAttempts > 0);
        return $number;
    }

    private function buildAddressData(Request $request, \App\Entity\User $user): array
    {
        $addressId = $request->request->get('address_id');

        if ($addressId) {
            foreach ($user->getAddresses() as $address) {
                if ($address->getId() === (int) $addressId) {
                    return [
                        'fullName'  => $address->getFullName(),
                        'phone'     => $address->getPhone(),
                        'city'      => $address->getCity(),
                        'street'    => $address->getStreet(),
                        'building'  => $address->getBuilding(),
                        'apartment' => $address->getApartment(),
                        'zip'       => $address->getZip(),
                    ];
                }
            }
        }

        return [
            'fullName'  => $request->request->get('full_name'),
            'phone'     => $request->request->get('phone'),
            'email'     => $request->request->get('email'),
            'city'      => $request->request->get('city'),
            'street'    => $request->request->get('street'),
            'building'  => $request->request->get('building'),
            'apartment' => $request->request->get('apartment'),
            'zip'       => $request->request->get('zip'),
            'country'   => $request->request->get('country', 'RU'),
        ];
    }

    private function calculateCartWeight(\App\Entity\Cart $cart): float
    {
        return $this->getCartWeight($cart);
    }
}
