<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\BrandInvite;
use App\Entity\BrandUser;
use App\Entity\Notification;
use App\Notification\NotificationDispatcher;
use App\Repository\BrandUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Обработка приглашений в команду бренда.
 * Роут намеренно НЕ находится под /brand, чтобы не требовать ROLE_BRAND_MANAGER
 * у принимающего пользователя до момента принятия.
 */
class InviteAcceptController extends AbstractController
{
    #[Route('/invite/accept/{token}', name: 'invite_accept')]
    public function accept(
        string $token,
        EntityManagerInterface $em,
        BrandUserRepository $brandUserRepo,
        NotificationDispatcher $notifier,
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var \App\Entity\User $user */
        $user   = $this->getUser();
        $invite = $em->getRepository(BrandInvite::class)->findOneBy(['token' => $token]);

        if (!$invite || $invite->isExpired() || $invite->isAccepted()) {
            $this->addFlash('error', 'Приглашение недействительно или истекло');
            return $this->redirectToRoute('account_dashboard');
        }

        // Приглашение адресовано другому email
        if (strtolower($invite->getEmail()) !== strtolower($user->getUserIdentifier())) {
            $this->addFlash('error', 'Это приглашение предназначено для другого пользователя');
            return $this->redirectToRoute('account_dashboard');
        }

        // Check user isn't already a member
        $existing = $brandUserRepo->findOneBy(['brand' => $invite->getBrand(), 'user' => $user]);
        if ($existing) {
            $this->addFlash('info', 'Вы уже состоите в команде этого бренда');
            return $this->redirectToRoute('brand_dashboard');
        }

        $brandUser = new BrandUser();
        $brandUser->setBrand($invite->getBrand());
        $brandUser->setUser($user);
        $brandUser->setRole($invite->getRole());
        $brandUser->setInvitedBy($invite->getInvitedBy());
        $brandUser->setAcceptedAt(new \DateTimeImmutable());

        // Grant brand roles
        $roles = $user->getRoles();
        if (!in_array('ROLE_BRAND_MANAGER', $roles, true)) {
            $roles[] = 'ROLE_BRAND_MANAGER';
        }
        if ($invite->getRole() === BrandUser::ROLE_OWNER && !in_array('ROLE_BRAND_OWNER', $roles, true)) {
            $roles[] = 'ROLE_BRAND_OWNER';
        }
        $user->setRoles(array_values(array_unique($roles)));

        $invite->setAcceptedAt(new \DateTimeImmutable());

        $em->persist($brandUser);
        $em->flush();

        $invitedBy = $invite->getInvitedBy();
        if ($invitedBy) {
            $notifier->dispatch(
                $invitedBy,
                Notification::TYPE_BRAND_INVITE,
                "{$user->getEmail()} принял приглашение в {$invite->getBrand()->getTitle()}",
                "Пользователь {$user->getEmail()} присоединился к команде бренда с ролью {$invite->getRole()}.",
                ['brand_id' => $invite->getBrand()->getId(), 'user_email' => $user->getEmail()],
            );
        }

        $this->addFlash('success', "Вы присоединились к команде {$invite->getBrand()->getTitle()}!");
        return $this->redirectToRoute('brand_dashboard');
    }
}
