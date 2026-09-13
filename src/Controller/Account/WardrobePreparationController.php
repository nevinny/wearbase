<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Repository\WardrobeItemRepository;
use App\Service\FamilyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WardrobePreparationController extends AbstractController
{
    #[Route('/account/wardrobe/preparation', name: 'account_wardrobe_preparation', methods: ['GET'])]
    public function index(Request $request, FamilyService $families, WardrobeItemRepository $items): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $subject = $families->resolveMember($actor, $request->query->getInt('member') ?: null);
        $candidates = $items->findNeedingPreparation($subject, max(0, $request->query->getInt('after')));
        $hasMore = count($candidates) > 30;
        $candidates = array_slice($candidates, 0, 30);

        return $this->render('account/wardrobe/preparation.html.twig', [
            'currentMember' => $subject,
            'items' => $candidates,
            'nextAfter' => $hasMore ? $candidates[array_key_last($candidates)]->getId() : null,
        ]);
    }
}
