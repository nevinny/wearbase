<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\AiUsageLog;
use App\Entity\User;
use App\Repository\WardrobeItemRepository;
use App\Service\AiUsageTracker;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeAiException;
use App\Service\Wardrobe\WardrobeOutfitService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account/wardrobe/outfits', name: 'account_wardrobe_outfits', methods: ['GET', 'POST'])]
class WardrobeOutfitController extends AbstractController
{
    public function __invoke(
        Request $request,
        FamilyService $familyService,
        WardrobeItemRepository $items,
        WardrobeOutfitService $outfits,
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
                    $result = $outfits->suggest($actor, $wardrobeItems, $prompt);
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
}
