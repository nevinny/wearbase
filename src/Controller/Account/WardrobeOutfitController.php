<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\AiUsageLog;
use App\Entity\User;
use App\Entity\WardrobeOutfit;
use App\Repository\WardrobeItemRepository;
use App\Service\AiUsageTracker;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeAiException;
use App\Service\Wardrobe\WardrobeOutfitService;
use App\Service\Wardrobe\WardrobeOutfitLearningService;
use App\Service\Wardrobe\WardrobeOnboardingService;
use App\Service\Wardrobe\WardrobeWearService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account/wardrobe/outfits')]
class WardrobeOutfitController extends AbstractController
{
    #[Route('', name: 'account_wardrobe_outfits', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        FamilyService $familyService,
        WardrobeItemRepository $items,
        WardrobeOutfitService $outfits,
        WardrobeOutfitLearningService $learning,
        RateLimiterFactory $wardrobeAiLimiter,
        AiUsageTracker $usageTracker,
    ): Response {
        /** @var User $actor */
        $actor = $this->getUser();
        $member = $familyService->resolveMember($actor, $request->query->has('member') ? $request->query->getInt('member') : null);
        $wardrobeItems = $items->findActiveForUser($member);
        $result = [];
        $error = null;
        $prompt = mb_substr(trim((string) $request->request->get('prompt')), 0, 300);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('wardrobe_outfits', (string) $request->request->get('_token'))) {
                $error = 'Недействительный токен';
            } elseif (!$wardrobeAiLimiter->create((string) $actor->getId())->consume()->isAccepted()) {
                $error = 'Лимит AI-подсказок на сегодня';
                $usageTracker->recordError($actor, AiUsageLog::FEATURE_WARDROBE_OUTFIT, $error);
            } else {
                try {
                    $result = $outfits->suggest($actor, $wardrobeItems, $prompt, $learning->context($member));
                    $result = $learning->remember($actor, $member, $prompt, $result);
                } catch (WardrobeAiException|\DomainException $exception) {
                    $error = $exception->getMessage();
                    $usageTracker->recordError($actor, AiUsageLog::FEATURE_WARDROBE_OUTFIT, $error);
                } catch (\Throwable) {
                    $error = 'Не удалось собрать образы, попробуйте позже';
                    $usageTracker->recordError($actor, AiUsageLog::FEATURE_WARDROBE_OUTFIT, $error);
                }
            }
        }

        return $this->render('account/wardrobe/outfits.html.twig', [
            'member' => $member,
            'prompt' => $prompt,
            'outfits' => $result,
            'error' => $error,
            'itemCount' => count($wardrobeItems),
        ]);
    }

    #[Route('/{id}/reaction', name: 'account_wardrobe_outfit_reaction', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function react(
        int $id,
        Request $request,
        FamilyService $familyService,
        WardrobeOutfitLearningService $learning,
        WardrobeOnboardingService $onboarding,
        WardrobeWearService $wear,
    ): Response
    {
        if (!$this->isCsrfTokenValid('wardrobe_outfit_reaction_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Недействительный токен');
        }

        /** @var User $user */
        $user = $this->getUser();
        $member = $familyService->resolveMember($user, $request->query->has('member') ? $request->query->getInt('member') : null);
        try {
            $reaction = (string) $request->request->get('reaction');
            if ($reaction === WardrobeOutfit::REACTION_WORN) {
                $wear->recordOutfitWorn($user, $member, $id);
            } else {
                $learning->react($user, $member, $id, $reaction);
            }
            $onboarding->complete($user, $member);
            $this->addFlash('success', $reaction === WardrobeOutfit::REACTION_WORN
                ? 'Образ и носки вещей сохранены'
                : 'Спасибо — следующий подбор учтёт эту реакцию');
        } catch (\InvalidArgumentException|\DomainException $exception) {
            throw $this->createNotFoundException($exception->getMessage());
        }

        return $this->redirectToRoute('account_wardrobe_outfits', $member->getId() === $user->getId() ? [] : ['member' => $member->getId()]);
    }
}
