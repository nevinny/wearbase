<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Brand;
use App\Entity\Cart;
use App\Entity\CartItem;
use App\Entity\Notification;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * POST /checkout/confirm — контур оформления заказа, до этого не покрытый вовсе.
 *
 * Регрессы, ради которых тест написан:
 *  1. Уведомления рендерились ДО flush → order.id = null: ссылка в письме бренду вела на
 *     список заказов (prod), а в dev/test выбрасывала InvalidParameterException внутри
 *     транзакции → 500 и заказ не создавался вообще.
 *  2. Ранний `return` из wrapInTransaction при нехватке остатка коммитил уже созданные
 *     заказы предыдущих брендов (фантомные заказы) либо падал в 500.
 *  3. Мультибрендовая корзина с онлайн-оплатой не оплачивалась никогда — покупатель
 *     молча получал заказы и «шлюз недоступен».
 *  4. Текст флеша выбирался по платформенным реквизитам (для подписок), а не по счёту бренда.
 */
final class CheckoutConfirmTest extends AuthenticatedWebTestCase
{
    private const ADDRESS = [
        'first_name' => 'Иван',
        'last_name'  => 'Покупателев',
        'phone'      => '+79001234567',
        'city'       => 'Москва',
        'street'     => 'Тверская',
        'house'      => '1',
    ];

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }

    /** @return Order[] Заказы бренда — по id, чтобы не зависеть от отсоединённых после запроса объектов. */
    private function ordersOfBrand(int $brandId): array
    {
        return $this->em()->createQuery('SELECT o FROM App\\Entity\\Order o WHERE o.brand = :b')
            ->setParameter('b', $brandId)
            ->getResult();
    }

    /** Покупатель с подтверждённым email — иначе чекаут уводит на account_security. */
    private function loginVerifiedCustomer(KernelBrowser $client): User
    {
        $user = $this->loginAsCustomer($client);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->em()->flush();

        return $user;
    }

    private function makeBrandWithVariant(string $slug, int $stock = 5): ProductVariant
    {
        $em = $this->em();

        $brand = (new Brand())
            ->setTitle('Бренд ' . $slug)
            ->setSlug($slug)
            ->setStatus(Statuses::Active);
        $em->persist($brand);

        $product = (new Product())
            ->setTitle('Товар ' . $slug)
            ->setBrand($brand)
            ->setStatus(Statuses::Active);
        $em->persist($product);

        $variant = (new ProductVariant())
            ->setProduct($product)
            ->setSize('M')
            ->setPrice('1000.00')
            ->setStockQty($stock)
            ->setStatus('active');
        $em->persist($variant);

        return $variant;
    }

    /** @param array<int, array{0: ProductVariant, 1: int}> $lines вариант + количество */
    private function fillCart(User $user, array $lines): Cart
    {
        $em   = $this->em();
        $cart = $em->getRepository(Cart::class)->findOneBy(['user' => $user]) ?? (new Cart())->setUser($user);
        $em->persist($cart);

        // Покупатель из фабрики один на весь прогон: позиции, оставшиеся от предыдущего
        // сценария (тесты, где чекаут отклонён, корзину не чистят), сделали бы корзину
        // мультибрендовой и увели следующий сценарий не в ту ветку.
        foreach ($cart->getItems() as $stale) {
            $cart->getItems()->removeElement($stale);
            $em->remove($stale);
        }
        $em->flush();

        foreach ($lines as [$variant, $qty]) {
            $item = (new CartItem())->setCart($cart)->setVariant($variant)->setQty($qty);
            $em->persist($item);
            $cart->addItem($item);
        }
        $em->flush();

        return $cart;
    }

    /**
     * Токен берём из живой формы чекаута: вне запроса сессии ещё нет, а CSRF-хранилище
     * сессионное (SessionTokenStorage) — прямой вызов token_manager падает.
     *
     * @param array<string, string> $extra
     */
    private function submitCheckout(KernelBrowser $client, string $paymentMethod, array $extra = []): void
    {
        $crawler = $client->request('GET', '/checkout');
        $this->assertResponseIsSuccessful('страница чекаута открывается');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/checkout/confirm', array_merge(self::ADDRESS, [
            '_token'          => $token,
            'payment_method'  => $paymentMethod,
            'delivery_method' => 'cdek',
            'country'         => 'RU',
        ], $extra));
    }

    public function testOrderIsCreatedAndBrandNotificationCarriesOrderId(): void
    {
        $this->skipIfNoDatabase();

        $client  = static::createClient();
        $user    = $this->loginVerifiedCustomer($client);
        $variant = $this->makeBrandWithVariant('checkout-happy-path');
        $this->fillCart($user, [[$variant, 2]]);
        // id фиксируем ДО запроса: после него kernel перезагружен и lazy-прокси уже не поднять.
        $brandId   = $variant->getProduct()->getBrand()->getId();
        $variantId = $variant->getId();

        $this->submitCheckout($client, Order::PAYMENT_METHOD_RECEIPT);

        // Регресс 1: раньше здесь был 500 (InvalidParameterException на url() с order.id = null).
        $this->assertResponseRedirects();

        $em = $this->em();

        $orders = $this->ordersOfBrand($brandId);
        $this->assertCount(1, $orders, 'заказ создан');
        $order = $orders[0];
        $this->assertNotNull($order->getId());
        $this->assertSame(3, $em->find(ProductVariant::class, $variantId)->getStockQty(), 'остаток списан (5 − 2)');

        $notifications = $em->getRepository(Notification::class)->findBy(['type' => Notification::TYPE_ORDER_NEW]);
        $this->assertNotEmpty($notifications, 'уведомления о заказе созданы');

        $withOrderId = array_filter(
            $notifications,
            static fn (Notification $n) => ($n->getData()['order_id'] ?? null) === $order->getId(),
        );
        $this->assertNotEmpty($withOrderId, 'в уведомлении лежит реальный id заказа, а не null');
    }

    public function testInsufficientStockOnSecondLineCreatesNoOrdersAtAll(): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        $user   = $this->loginVerifiedCustomer($client);

        $ok    = $this->makeBrandWithVariant('checkout-stock-ok', 10);
        $short = $this->makeBrandWithVariant('checkout-stock-short', 1);
        $this->fillCart($user, [[$ok, 1], [$short, 5]]);
        $okBrandId    = $ok->getProduct()->getBrand()->getId();
        $shortBrandId = $short->getProduct()->getBrand()->getId();
        $okVariantId  = $ok->getId();

        $this->submitCheckout($client, Order::PAYMENT_METHOD_RECEIPT);

        $this->assertResponseRedirects('/cart');

        $em = $this->em();

        // Регресс 2: раньше заказ первого бренда коммитился, а покупателя выкидывало в корзину.
        $this->assertCount(0, $this->ordersOfBrand($okBrandId));
        $this->assertCount(0, $this->ordersOfBrand($shortBrandId));
        $this->assertSame(10, $em->find(ProductVariant::class, $okVariantId)->getStockQty(), 'остаток не тронут');
    }

    public function testMultiBrandCartWithCardPaymentIsRejectedBeforeCreatingOrders(): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        $user   = $this->loginVerifiedCustomer($client);

        $first  = $this->makeBrandWithVariant('checkout-multi-a');
        $second = $this->makeBrandWithVariant('checkout-multi-b');
        $this->fillCart($user, [[$first, 1], [$second, 1]]);
        $firstBrandId  = $first->getProduct()->getBrand()->getId();
        $secondBrandId = $second->getProduct()->getBrand()->getId();

        $this->submitCheckout($client, Order::PAYMENT_METHOD_CARD);

        // Регресс 3: раньше заказы создавались, а оплата молча не запускалась.
        $this->assertResponseRedirects('/cart');
        $em = $this->em();
        $this->assertCount(0, $this->ordersOfBrand($firstBrandId));
        $this->assertCount(0, $this->ordersOfBrand($secondBrandId));
    }

    public function testCardPaymentForBrandWithoutAccountSaysPaymentIsNotSetUp(): void
    {
        $this->skipIfNoDatabase();

        $client  = static::createClient();
        $user    = $this->loginVerifiedCustomer($client);
        $variant = $this->makeBrandWithVariant('checkout-no-account');
        $this->fillCart($user, [[$variant, 1]]);
        $brandId = $variant->getProduct()->getBrand()->getId();

        $this->submitCheckout($client, Order::PAYMENT_METHOD_CARD);

        $this->assertResponseRedirects();
        $orders = $this->ordersOfBrand($brandId);
        $this->assertCount(1, $orders, 'заказ создаётся даже без онлайн-оплаты');
        $this->assertSame(Order::PAYMENT_PENDING, $orders[0]->getPaymentStatus());

        // Регресс 4: диагноз по счёту бренда, а не по платформенным реквизитам подписок.
        $flashes = $client->getRequest()->getSession()->getFlashBag()->peekAll();
        $this->assertStringContainsString(
            'не настроена',
            implode(' ', array_merge(...array_values($flashes))),
        );
    }

    public function testUnverifiedEmailIsSentToSecuritySettings(): void
    {
        $this->skipIfNoDatabase();

        $client  = static::createClient();
        $user    = $this->loginAsCustomer($client);
        $user->setEmailVerifiedAt(null);
        $this->em()->flush();

        $variant = $this->makeBrandWithVariant('checkout-unverified');
        $this->fillCart($user, [[$variant, 1]]);

        $this->submitCheckout($client, Order::PAYMENT_METHOD_RECEIPT);

        $this->assertResponseRedirects('/account/security');
    }
}
