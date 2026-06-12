<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Controller\Cart\CartController;
use App\Entity\User;
use App\Repository\CartRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Слияние гостевой корзины с корзиной пользователя при логине.
 * Гостевая корзина ищется по токену из данных сессии (cart_token) —
 * он переживает миграцию сессии, в отличие от session ID.
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
class CartMergeOnLoginListener
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return; // admin-firewall (Nevinny User) — корзин нет
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $token = $session->get(CartController::SESSION_TOKEN_KEY)
            // легаси: корзины до токенов привязаны к PHP session ID
            ?? $session->getId();

        $guestCart = $this->carts->findOneBy(['sessionId' => $token, 'user' => null]);
        if (!$guestCart || $guestCart->getItems()->isEmpty()) {
            return;
        }

        $userCart = $this->carts->findOneBy(['user' => $user]);

        if (!$userCart) {
            // Корзины у пользователя нет — гостевая становится его
            $guestCart->setUser($user);
            $guestCart->setSessionId(null);
        } else {
            // Слияние позиций: совпадающий вариант — суммируем (в пределах остатка)
            foreach ($guestCart->getItems()->toArray() as $item) {
                $variant = $item->getVariant();
                $existing = $variant ? $userCart->findItemByVariant($variant) : null;
                if ($existing) {
                    $maxQty = $variant?->getStockQty() ?? PHP_INT_MAX;
                    $existing->setQty(min($existing->getQty() + $item->getQty(), $maxQty));
                    $guestCart->removeItem($item);
                    $this->em->remove($item);
                } else {
                    $guestCart->removeItem($item);
                    $item->setCart($userCart);
                    $userCart->addItem($item);
                }
            }
        }

        $this->em->flush();
    }
}
