<?php

declare(strict_types=1);

namespace App\Controller\BrandLk;

use App\Entity\BrandInvite;
use App\Entity\BrandUser;
use App\Notification\EmailNotifier;
use App\Repository\BrandUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/brand/team', name: 'brand_team')]
class BrandTeamController extends BrandDashboardController
{
    #[Route('', name: '')]
    public function index(BrandUserRepository $brandUserRepo): Response
    {
        $brand   = $this->getActiveBrand();
        $members = $brandUserRepo->findBy(['brand' => $brand], ['createdAt' => 'ASC']);

        return $this->render('brand_lk/team/index.html.twig', [
            'brand'   => $brand,
            'members' => $members,
        ]);
    }

    #[Route('/invite', name: '_invite', methods: ['POST'])]
    public function invite(
        Request $request,
        EntityManagerInterface $em,
        BrandUserRepository $brandUserRepo,
        EmailNotifier $emailNotifier,
    ): Response {
        $brand = $this->getActiveBrand();

        if (!$this->isCsrfTokenValid('invite_team', $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('brand_team');
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        // Only owners can invite
        $myBrandUser = $brandUserRepo->findOneBy(['brand' => $brand, 'user' => $currentUser]);

        if (!$myBrandUser || $myBrandUser->getRole() !== BrandUser::ROLE_OWNER) {
            $this->addFlash('error', 'Только владелец может приглашать участников');
            return $this->redirectToRoute('brand_team');
        }

        $email = trim((string) $request->request->get('email'));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Некорректный email');
            return $this->redirectToRoute('brand_team');
        }

        // Уже состоит в команде этого бренда?
        $existingUser = $em->getRepository(\App\Entity\User::class)->findOneBy(['email' => $email]);
        if ($existingUser !== null && $brandUserRepo->findOneBy(['brand' => $brand, 'user' => $existingUser]) !== null) {
            $this->addFlash('error', 'Этот пользователь уже в команде бренда');
            return $this->redirectToRoute('brand_team');
        }

        // Уже есть активное (непринятое, не истёкшее) приглашение на этот email?
        foreach ($em->getRepository(BrandInvite::class)->findBy(['brand' => $brand, 'email' => $email, 'acceptedAt' => null]) as $pending) {
            if (!$pending->isExpired()) {
                $this->addFlash('error', 'Приглашение на этот email уже отправлено');
                return $this->redirectToRoute('brand_team');
            }
        }

        $invite = new BrandInvite();
        $invite->setBrand($brand);
        $invite->setEmail($email);
        $role = $request->request->get('role', BrandUser::ROLE_MANAGER);
        $invite->setRole(in_array($role, [BrandUser::ROLE_OWNER, BrandUser::ROLE_MANAGER], true) ? $role : BrandUser::ROLE_MANAGER);
        $invite->setInvitedBy($currentUser);

        $em->persist($invite);
        $em->flush();

        $emailNotifier->send(
            $email,
            "Приглашение в команду {$brand->getTitle()} — WEARBASE",
            'brand_invite',
            [
                'token' => $invite->getToken(),
                'brand' => $brand,
                'invitedBy' => $currentUser,
                'role' => $invite->getRole(),
            ],
        );

        $this->addFlash('success', "Приглашение отправлено на {$email}");
        return $this->redirectToRoute('brand_team');
    }

    #[Route('/member/{id}/remove', name: '_member_remove', methods: ['POST'])]
    public function removeMember(Request $request, BrandUser $member, EntityManagerInterface $em, BrandUserRepository $brandUserRepo): Response
    {
        $brand = $this->getActiveBrand();

        if (!$this->isCsrfTokenValid('remove_member', $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('brand_team');
        }

        if ($member->getBrand() !== $brand) {
            throw $this->createAccessDeniedException();
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        if ($member->getUser() === $currentUser) {
            $this->addFlash('error', 'Нельзя удалить себя');
            return $this->redirectToRoute('brand_team');
        }

        if ($member->getRole() === BrandUser::ROLE_OWNER) {
            $this->addFlash('error', 'Нельзя удалить владельца');
            return $this->redirectToRoute('brand_team');
        }

        // Только owner может удалять
        $myBrandUser = $brandUserRepo->findOneBy(['brand' => $brand, 'user' => $currentUser]);
        if (!$myBrandUser || $myBrandUser->getRole() !== BrandUser::ROLE_OWNER) {
            $this->addFlash('error', 'Только владелец может удалять участников');
            return $this->redirectToRoute('brand_team');
        }

        $em->remove($member);
        $em->flush();
        $this->addFlash('success', 'Участник удалён');

        return $this->redirectToRoute('brand_team');
    }

}
