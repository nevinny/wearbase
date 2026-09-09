<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Repository\WardrobeItemRepository;
use App\Repository\PurchaseRequestRepository;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeOnboardingService;
use App\Service\Wardrobe\WardrobeEngagementService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WardrobeAppController extends AbstractController
{
    #[Route('/account/wardrobe-app', name: 'account_wardrobe_app', methods: ['GET'])]
    public function index(
        Request $request,
        FamilyService $familyService,
        WardrobeItemRepository $itemRepository,
        WardrobeOnboardingService $onboardingService,
        PurchaseRequestRepository $purchaseRequests,
        WardrobeEngagementService $engagement,
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $currentMember = $familyService->resolveMember($user, $this->memberParam($request));
        $familyMembers = [];
        foreach ($familyService->membersFor($user) as $member) {
            $canManage = $familyService->canManage($user, $member);

            $familyMembers[] = [
                'member' => $member,
                'canManage' => $canManage,
                'itemCount' => $canManage ? $itemRepository->countActiveForUser($member) : null,
                'isOwn' => $member->getId() === $user->getId(),
            ];
        }

        return $this->render('account/family_wardrobe/dashboard.html.twig', [
            'familyMembers' => $familyMembers,
            'canManageFamily' => $user->getFamilyRole() !== User::FAMILY_ROLE_CHILD,
            'currentMember' => $currentMember,
            'onboarding' => $onboardingService->overview($user, $currentMember),
            'familyActiveSection' => 'wardrobe-app',
            'pendingPurchaseCount' => $purchaseRequests->countPendingVisibleTo($user),
            'engagement' => $engagement->summary($currentMember),
            'familyCaptureUrl' => $this->generateUrl('account_wardrobe_wear_index', $currentMember->getId() === $user->getId() ? [] : ['member' => $currentMember->getId()]),
        ]);
    }

    #[Route('/account/wardrobe-app/onboarding', name: 'account_wardrobe_app_onboarding', methods: ['POST'])]
    public function onboarding(
        Request $request,
        FamilyService $familyService,
        WardrobeOnboardingService $onboardingService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $memberId = $request->request->getInt('member');
        $subject = $familyService->resolveMember($user, $memberId > 0 ? $memberId : null);

        if (!$this->isCsrfTokenValid('wardrobe_onboarding_'.$subject->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }

        match ($request->request->getString('action')) {
            'skip' => $onboardingService->skip($user, $subject),
            'resume' => $onboardingService->resume($user, $subject),
            default => throw $this->createNotFoundException('Неизвестное действие'),
        };

        return $this->redirectToRoute('account_wardrobe_app', $subject->getId() === $user->getId()
            ? []
            : ['member' => $subject->getId()]);
    }

    private function memberParam(Request $request): ?int
    {
        $member = $request->query->getInt('member');

        return $member > 0 ? $member : null;
    }
}
