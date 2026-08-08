<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Repository\WardrobeItemRepository;
use App\Service\FamilyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WardrobeAppController extends AbstractController
{
    #[Route('/account/wardrobe-app', name: 'account_wardrobe_app', methods: ['GET'])]
    public function index(FamilyService $familyService, WardrobeItemRepository $itemRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();

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
            'currentMember' => $user,
            'familyActiveSection' => 'wardrobe-app',
        ]);
    }
}
