<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Dto\Family\ChildProfileInput;
use App\Form\Account\FamilyChildFormType;
use App\Repository\FamilyInviteRepository;
use App\Repository\WardrobeItemRepository;
use App\Service\FamilyService;
use App\Service\Family\ChildProfileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ЛК «Моя семья»: члены семьи, добавление managed-детей, инвайты взрослых.
 */
#[Route('/account/family', name: 'account_family_')]
class FamilyController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        FamilyService $familyService,
        FamilyInviteRepository $inviteRepo,
        WardrobeItemRepository $itemRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $family  = $user->getFamily();
        $members = $familyService->membersFor($user);

        $itemCounts = [];
        $claimUrls  = [];
        foreach ($members as $member) {
            if ($familyService->canManage($user, $member)) {
                $itemCounts[$member->getId()] = $itemRepo->countActiveForUser($member);
            }
            if ($user->isFamilyParent()) {
                $url = $familyService->claimUrl($member);
                if ($url !== null) {
                    $claimUrls[$member->getId()] = $url;
                }
            }
        }

        $invites    = $family !== null && $user->isFamilyParent() ? $inviteRepo->findPendingForFamily($family) : [];
        $inviteUrls = [];
        foreach ($invites as $invite) {
            $inviteUrls[$invite->getId()] = $familyService->inviteUrl($invite);
        }

        return $this->render('account/family/index.html.twig', [
            'family'     => $family,
            'members'    => $members,
            'itemCounts' => $itemCounts,
            'claimUrls'  => $claimUrls,
            'invites'    => $invites,
            'inviteUrls' => $inviteUrls,
        ]);
    }

    #[Route('/add', name: 'add', methods: ['GET', 'POST'])]
    public function add(Request $request, ChildProfileService $childProfiles): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->getFamilyRole() === User::FAMILY_ROLE_CHILD) {
            throw $this->createAccessDeniedException('Добавлять членов семьи может только родитель');
        }

        $form = $this->createForm(FamilyChildFormType::class, new ChildProfileInput());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ChildProfileInput $data */
            $data = $form->getData();
            $child = $childProfiles->create($user, $data);

            $this->addFlash('success', sprintf('%s добавлен(а) в семью', $child->getFirstName()));
            return $this->redirectToRoute('account_family_index');
        }

        return $this->render('account/family/add.html.twig', [
            'form' => $form,
            'profileMode' => false,
        ]);
    }

    #[Route('/profile', name: 'profile', methods: ['GET', 'POST'])]
    public function profile(Request $request, ChildProfileService $childProfiles): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($user->getFamilyRole() !== User::FAMILY_ROLE_CHILD) {
            throw $this->createAccessDeniedException('Анкета доступна детскому профилю');
        }

        $form = $this->createForm(FamilyChildFormType::class, $childProfiles->input($user));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ChildProfileInput $data */
            $data = $form->getData();
            $childProfiles->updateSelf($user, $data);
            $this->addFlash('success', 'Анкета сохранена');

            return $this->redirectToRoute('account_wardrobe_app');
        }

        return $this->render('account/family/add.html.twig', [
            'form' => $form,
            'profileMode' => true,
        ]);
    }

    #[Route('/invite', name: 'invite', methods: ['POST'])]
    public function invite(Request $request, FamilyService $familyService): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->getFamilyRole() === User::FAMILY_ROLE_CHILD) {
            throw $this->createAccessDeniedException('Приглашать членов семьи может только родитель');
        }

        if (!$this->isCsrfTokenValid('family_invite', $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('account_family_index');
        }

        $role = (string) $request->request->get('role');
        if (!in_array($role, [User::FAMILY_ROLE_PARENT, User::FAMILY_ROLE_CHILD], true)) {
            $this->addFlash('error', 'Недопустимая роль приглашения');
            return $this->redirectToRoute('account_family_index');
        }

        $email = trim((string) $request->request->get('email'));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->addFlash('error', 'Укажите корректный email');
            return $this->redirectToRoute('account_family_index');
        }

        $familyService->createInvite($user, $role, $email !== '' ? $email : null);
        $this->addFlash('success', 'Приглашение создано — отправьте ссылку члену семьи');

        return $this->redirectToRoute('account_family_index');
    }

    #[Route('/invite/{id}/revoke', name: 'invite_revoke', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function revokeInvite(
        int $id,
        Request $request,
        FamilyInviteRepository $inviteRepo,
        FamilyService $familyService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $invite = $inviteRepo->find($id);
        if ($invite === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('family_invite_revoke_'.$id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Недействительный токен');
        }

        $familyService->revokeInvite($user, $invite);
        $this->addFlash('success', 'Приглашение отозвано');

        return $this->redirectToRoute('account_family_index');
    }

    #[Route('/invite/{id}/renew', name: 'invite_renew', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function renewInvite(
        int $id,
        Request $request,
        FamilyInviteRepository $inviteRepo,
        FamilyService $familyService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $invite = $inviteRepo->find($id);
        if ($invite === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('family_invite_renew_'.$id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Недействительный токен');
        }

        $familyService->renewInvite($user, $invite);
        $this->addFlash('success', 'Создана новая ссылка; прежняя больше не действует');

        return $this->redirectToRoute('account_family_index');
    }

    #[Route('/child/{id}/access/renew', name: 'child_access_renew', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function renewChildAccess(
        int $id,
        Request $request,
        FamilyService $familyService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('family_child_access_renew_'.$id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Недействительный токен');
        }
        $child = $familyService->resolveMember($user, $id);
        $familyService->renewChildAccess($user, $child);
        $this->addFlash('success', 'Создана новая ссылка для входа ребёнка');

        return $this->redirectToRoute('account_family_index');
    }

    #[Route('/child/{id}/access/revoke', name: 'child_access_revoke', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function revokeChildAccess(
        int $id,
        Request $request,
        FamilyService $familyService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('family_child_access_revoke_'.$id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Недействительный токен');
        }
        $child = $familyService->resolveMember($user, $id);
        $familyService->revokeChildAccess($user, $child);
        $this->addFlash('success', 'Ссылка для входа ребёнка отозвана');

        return $this->redirectToRoute('account_family_index');
    }
}
