<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\AiUsageLog;
use App\Entity\User;
use App\Entity\WardrobeCircleMember;
use App\Entity\WardrobeOutfit;
use App\Repository\WardrobeConsentRepository;
use App\Repository\WardrobeCircleMemberRepository;
use App\Repository\WardrobeItemRepository;
use App\Repository\WardrobeOutfitRepository;
use App\Repository\WardrobeOutfitShareRepository;
use App\Service\AiUsageTracker;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeAiException;
use App\Service\Wardrobe\WardrobeConsentService;
use App\Service\Wardrobe\WardrobeOutfitService;
use App\Service\Wardrobe\WardrobeOutfitLearningService;
use App\Service\Wardrobe\WardrobeOnboardingService;
use App\Service\Wardrobe\WardrobeStylistContextBuilder;
use App\Service\Wardrobe\WardrobeWearService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Service\WardrobeAiAllowance;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account/wardrobe/outfits')]
class WardrobeOutfitController extends AbstractController
{
    #[Route('', name: 'account_wardrobe_outfits', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        FamilyService $familyService,
        WardrobeItemRepository $items,
        WardrobeOutfitRepository $outfitRepo,
        WardrobeOutfitService $outfits,
        WardrobeOutfitLearningService $learning,
        WardrobeConsentRepository $consents,
        WardrobeOutfitShareRepository $shareRepo,
        WardrobeAiAllowance $aiAllowance,
        AiUsageTracker $usageTracker,
        WardrobeCircleMemberRepository $circles,
    ): Response {
        /** @var User $actor */
        $actor = $this->getUser();
        $member = $familyService->resolveMember($actor, $request->query->has('member') ? $request->query->getInt('member') : null);
        $wardrobeItems = $items->findActiveForUser($member);
        $dailyOutfits = $this->hydrateDailyOutfits($outfitRepo, $items, $member);
        $result = [];
        $error = null;
        $prompt = mb_substr(trim((string) $request->request->get('prompt')), 0, 300);
        $event = (string) $request->request->get('event');
        $weatherCondition = (string) $request->request->get('weather_condition');
        $temperatureBand = (string) $request->request->get('temperature_band');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('wardrobe_outfits', (string) $request->request->get('_token'))) {
                $error = 'Недействительный токен';
            } elseif (!in_array($weatherCondition, WardrobeStylistContextBuilder::WEATHER_CONDITIONS, true)
                || !in_array($temperatureBand, WardrobeStylistContextBuilder::TEMPERATURE_BANDS, true)) {
                $error = 'Выберите текущую погоду и температуру';
            } elseif (!$aiAllowance->consume($actor)) {
                $error = 'Лимит AI-подсказок на сегодня';
                $usageTracker->recordError($actor, AiUsageLog::FEATURE_WARDROBE_OUTFIT, $error);
            } else {
                try {
                    $result = $outfits->suggest($actor, $wardrobeItems, $prompt, $learning->context($member), $member, $event, $weatherCondition, $temperatureBand);
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
            'event' => $event,
            'weatherCondition' => $weatherCondition,
            'temperatureBand' => $temperatureBand,
            'outfits' => $result,
            'dailyOutfits' => $dailyOutfits,
            'error' => $error,
            'itemCount' => count($wardrobeItems),
            'personalizationGranted' => $consents->isPersonalizationGranted($member),
            'canControlPersonalization' => $member->getFamilyRole() !== User::FAMILY_ROLE_CHILD || $actor->isFamilyParent(),
            'outfitShares' => $shareRepo->findForWardrobeOwner($member),
            'canApproveShares' => $actor->isFamilyParent(),
            // Кружки actor'а для блока «Показать в кружке» (docs/circles-spec.md §2).
            'circleMemberships' => array_values(array_filter(
                $circles->findBy(['user' => $actor, 'status' => WardrobeCircleMember::STATUS_ACTIVE]),
                static fn (WardrobeCircleMember $m) => !$m->getCircle()->isDissolved(),
            )),
        ]);
    }

    #[Route('/consent/personalization', name: 'account_wardrobe_outfit_consent', methods: ['POST'])]
    public function consent(
        Request $request,
        FamilyService $familyService,
        WardrobeConsentService $consents,
    ): Response {
        if (!$this->isCsrfTokenValid('wardrobe_outfit_consent', (string) $request->request->get('_consent_token'))) {
            throw $this->createAccessDeniedException('Недействительный токен');
        }
        /** @var User $actor */
        $actor = $this->getUser();
        $member = $familyService->resolveMember($actor, $request->query->has('member') ? $request->query->getInt('member') : null);
        if ($request->request->get('action') === 'revoke') {
            $consents->revokePersonalization($actor, $member);
            $this->addFlash('success', 'Remote-стилист и персонализация отключены');
        } else {
            $consents->grantPersonalization($actor, $member);
            $this->addFlash('success', 'Remote-стилист и персонализация включены');
        }

        return $this->redirectToRoute('account_wardrobe_outfits', $member->getId() === $actor->getId() ? [] : ['member' => $member->getId()]);
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
                $learning->react($member, $id, $reaction);
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

    /**
     * Витрина «Образы на утро»: результат ночного пакетного конвейера за сегодня,
     * сгруппированный по поводу в порядке WardrobeOutfit::DAILY_OCCASIONS. Вещи
     * гидрируются по сохранённому снимку id; вещь, удалённую после генерации,
     * findActiveOneForUser() молча не вернёт — образ просто выходит с меньшим
     * набором вещей (как WardrobeWearService::recordOutfitWorn()).
     *
     * @return array<string, array{label:string, outfits: list<array{title:string,explanation:?string,items:list<\App\Entity\WardrobeItem>,feedbackId:int}>}>
     */
    private function hydrateDailyOutfits(WardrobeOutfitRepository $outfitRepo, WardrobeItemRepository $items, User $member): array
    {
        $grouped = [];
        foreach ($outfitRepo->findDailyForOwner($member, new \DateTimeImmutable('today')) as $outfit) {
            $occasion = $outfit->getOccasion();
            $hydratedItems = [];
            foreach ($outfit->getItems() as $snapshot) {
                $item = $items->findActiveOneForUser((int) ($snapshot['id'] ?? 0), $member);
                if ($item !== null) {
                    $hydratedItems[] = $item;
                }
            }
            $grouped[$occasion]['label'] ??= WardrobeOutfit::DAILY_OCCASIONS[$occasion] ?? $occasion;
            $grouped[$occasion]['outfits'][] = [
                'title' => $outfit->getTitle(),
                'explanation' => $outfit->getExplanation(),
                'items' => $hydratedItems,
                'feedbackId' => $outfit->getId(),
            ];
        }

        // Порядок групп — как объявлено в DAILY_OCCASIONS, а не как вернул SQL.
        $ordered = [];
        foreach (WardrobeOutfit::DAILY_OCCASIONS as $occasion => $label) {
            if (isset($grouped[$occasion])) {
                $ordered[$occasion] = $grouped[$occasion];
            }
        }

        return $ordered;
    }
}
