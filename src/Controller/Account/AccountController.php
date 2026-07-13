<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\Address;
use App\Entity\Order;
use App\Entity\User;
use App\Form\Account\AddressFormType;
use App\Form\Account\ProfileFormType;
use App\Notification\EmailNotifier;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account', name: 'account_')]
class AccountController extends AbstractController
{
    #[Route('', name: 'dashboard')]
    public function dashboard(OrderRepository $orderRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $allOrders    = $orderRepo->findBy(['customer' => $user], ['createdAt' => 'DESC']);
        $activeOrders = $orderRepo->findActiveOrdersForUser($user);

        $totalSpent = array_sum(array_map(
            fn($o) => (float) $o->getTotalAmount(),
            $allOrders
        ));

        return $this->render('account/dashboard.html.twig', [
            'user'          => $user,
            'recentOrders'  => array_slice($allOrders, 0, 5),
            'stats'         => [
                'totalOrders'  => count($allOrders),
                'activeOrders' => count($activeOrders),
                'totalSpent'   => $totalSpent,
            ],
        ]);
    }

    // ── Профиль ───────────────────────────────────────────────

    #[Route('/profile', name: 'profile')]
    public function profile(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        EmailNotifier $emailNotifier,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $oldEmail = $user->getEmail();
        $form = $this->createForm(ProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Смена email — сброс верификации
            if ($form->get('email')->getData() !== $oldEmail) {
                $token = bin2hex(random_bytes(32));
                $user->setEmailVerifiedAt(null);
                $user->setEmailVerificationToken($token);
                $emailNotifier->send($user, 'Подтвердите новый email — WEARBASE', 'verify_email', ['token' => $token]);
            }

            $plain = $form->get('newPassword')->getData();
            if ($plain) {
                $user->setPassword($hasher->hashPassword($user, $plain));
            }
            $em->flush();
            $this->addFlash('success', 'Профиль обновлён');
            return $this->redirectToRoute('account_profile');
        }

        return $this->render('account/profile.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    // ── Заказы ────────────────────────────────────────────────

    #[Route('/orders', name: 'orders')]
    public function orders(OrderRepository $repo): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $orders = $repo->findBy(['customer' => $user], ['createdAt' => 'DESC']);

        return $this->render('account/orders.html.twig', [
            'user'   => $user,
            'orders' => $orders,
        ]);
    }

    #[Route('/orders/{number}', name: 'order_show')]
    public function orderShow(string $number, OrderRepository $repo): Response
    {
        /** @var User $user */
        $user  = $this->getUser();
        $order = $repo->findOneBy(['orderNumber' => $number, 'customer' => $user]);

        if (!$order) {
            throw $this->createNotFoundException();
        }

        return $this->render('account/order_show.html.twig', [
            'user'  => $user,
            'order' => $order,
        ]);
    }

    #[Route('/orders/{number}/cancel', name: 'order_cancel', methods: ['POST'])]
    public function orderCancel(
        string $number,
        Request $request,
        OrderRepository $repo,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('cancel_order', $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('account_orders');
        }

        /** @var User $user */
        $user  = $this->getUser();
        $order = $repo->findOneBy(['orderNumber' => $number, 'customer' => $user]);

        if (!$order) {
            throw $this->createNotFoundException();
        }

        if (!$order->canBeCancelledByCustomer()) {
            $this->addFlash('error', 'Невозможно отменить заказ на данном этапе');
            return $this->redirectToRoute('account_order_show', ['number' => $number]);
        }

        $order->setStatus(Order::STATUS_CANCELLED);

        foreach ($order->getOrderItems() as $orderItem) {
            $variant = $orderItem->getVariant();
            if ($variant) {
                $variant->setStockQty($variant->getStockQty() + $orderItem->getQty());
            }
        }

        $em->flush();
        $this->addFlash('success', 'Заказ отменён');

        return $this->redirectToRoute('account_orders');
    }

    // ── Адреса ────────────────────────────────────────────────

    #[Route('/addresses', name: 'addresses')]
    public function addresses(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('account/addresses.html.twig', [
            'user'      => $user,
            'addresses' => $user->getAddresses(),
        ]);
    }

    #[Route('/addresses/new', name: 'address_new')]
    public function addressNew(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user    = $this->getUser();
        $address = new Address();
        $address->setUser($user);

        $form = $this->createForm(AddressFormType::class, $address);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($user->getAddresses()->isEmpty()) {
                $address->setIsDefault(true);
            }
            $em->persist($address);
            $em->flush();
            $this->addFlash('success', 'Адрес добавлен');
            return $this->redirectToRoute('account_addresses');
        }

        return $this->render('account/address_form.html.twig', [
            'user'  => $user,
            'form'  => $form,
            'title' => 'Новый адрес',
        ]);
    }

    #[Route('/addresses/{id}/edit', name: 'address_edit')]
    public function addressEdit(Address $address, Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($address->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(AddressFormType::class, $address);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Адрес обновлён');
            return $this->redirectToRoute('account_addresses');
        }

        return $this->render('account/address_form.html.twig', [
            'user'  => $user,
            'form'  => $form,
            'title' => 'Редактировать адрес',
        ]);
    }

    #[Route('/addresses/{id}/default', name: 'address_default', methods: ['POST'])]
    public function addressSetDefault(
        Address $address,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('set_default_address', $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('account_addresses');
        }

        /** @var User $user */
        $user = $this->getUser();
        if ($address->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        foreach ($user->getAddresses() as $a) {
            $a->setIsDefault(false);
        }
        $address->setIsDefault(true);
        $em->flush();

        return $this->redirectToRoute('account_addresses');
    }

    #[Route('/addresses/{id}/delete', name: 'address_delete', methods: ['POST'])]
    public function addressDelete(
        Address $address,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('delete_address', $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('account_addresses');
        }

        /** @var User $user */
        $user = $this->getUser();
        if ($address->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $em->remove($address);
        $em->flush();
        $this->addFlash('success', 'Адрес удалён');

        return $this->redirectToRoute('account_addresses');
    }

    // ── Безопасность ───────────────────────────────────────────

    #[Route('/security', name: 'security')]
    public function security(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('change_password', $request->request->get('_token'))) {
                $this->addFlash('error', 'Недействительный токен');
                return $this->redirectToRoute('account_security');
            }
            $current = $request->request->get('current_password');
            $new     = $request->request->get('new_password');

            if (!$hasher->isPasswordValid($user, $current)) {
                $this->addFlash('error', 'Неверный текущий пароль');
                return $this->redirectToRoute('account_security');
            }

            if (strlen($new) < 8) {
                $this->addFlash('error', 'Новый пароль должен быть не менее 8 символов');
                return $this->redirectToRoute('account_security');
            }

            $telegramChatId = $request->request->get('telegram_chat_id');
            if ($telegramChatId !== null) {
                $user->setTelegramChatId($telegramChatId ?: null);
            }

            $user->setPassword($hasher->hashPassword($user, $new));
            $em->flush();
            $this->addFlash('success', 'Пароль обновлён');
            return $this->redirectToRoute('account_security');
        }

        // Генерируем токен для привязки Telegram при каждом открытии страницы
        $telegramLink = null;
        if (!$user->getTelegramChatId()) {
            $botUsername = $this->getParameter('app.telegram_bot_username');
            $token = $user->generateTelegramLinkToken();
            $em->flush();
            $telegramLink = "https://t.me/{$botUsername}?start={$token}";
        }

        return $this->render('account/security.html.twig', [
            'user'         => $user,
            'telegramLink' => $telegramLink,
        ]);
    }

    // ── Избранное ──────────────────────────────────────────────

    #[Route('/favorites', name: 'favorites')]
    public function favorites(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('account/favorites.html.twig', [
            'user' => $user,
        ]);
    }
}
