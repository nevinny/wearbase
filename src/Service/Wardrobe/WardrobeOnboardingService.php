<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeItemDraft;
use App\Entity\WardrobeOnboarding;
use App\Repository\WardrobeItemDraftRepository;
use App\Repository\WardrobeItemRepository;
use App\Repository\WardrobeOnboardingRepository;
use App\Service\FamilyService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class WardrobeOnboardingService
{
    private const MIN_ITEMS_FOR_OUTFIT = 2;

    public function __construct(
        private readonly WardrobeOnboardingRepository $onboardings,
        private readonly WardrobeItemDraftRepository $drafts,
        private readonly WardrobeItemRepository $items,
        private readonly FamilyService $families,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @return array{
     *     stage:string,
     *     skipped:bool,
     *     completed:bool,
     *     activeBatchId:?string,
     *     itemCount:int,
     *     batchCounts:array{total:int,pending:int,recognized:int,failed:int}|null,
     *     nextAction:string
     * }
     */
    public function overview(User $actor, User $subject): array
    {
        $this->assertCanManage($actor, $subject);

        $onboarding = $this->onboardings->findForSubject($subject);
        $stage = $onboarding?->getStage() ?? WardrobeOnboarding::STAGE_INTRO;
        $skipped = $onboarding?->isSkipped() ?? false;
        $completed = $onboarding?->isCompleted() ?? false;
        $batchId = $onboarding?->getActiveBatchId();
        $batchCounts = $batchId !== null ? $this->batchCounts($subject, $batchId) : null;

        return [
            'stage' => $stage,
            'skipped' => $skipped,
            'completed' => $completed,
            'activeBatchId' => $batchId,
            'itemCount' => $this->items->countActiveForUser($subject),
            'batchCounts' => $batchCounts,
            'nextAction' => $this->nextAction($stage, $skipped, $batchCounts),
        ];
    }

    public function startBatch(User $actor, User $subject, string $batchId): WardrobeOnboarding
    {
        $this->assertCanManage($actor, $subject);

        return $this->mutate($subject, static fn (WardrobeOnboarding $onboarding) => $onboarding->startCapsule($batchId));
    }

    public function startOrResumeBatch(User $actor, User $subject, string $batchId): WardrobeOnboarding
    {
        $this->assertCanManage($actor, $subject);

        return $this->mutate($subject, static function (WardrobeOnboarding $onboarding) use ($batchId): void {
            if ($onboarding->getStage() !== WardrobeOnboarding::STAGE_CAPSULE || $onboarding->getActiveBatchId() === null) {
                $onboarding->startCapsule($batchId);
            }
        });
    }

    public function skip(User $actor, User $subject): WardrobeOnboarding
    {
        $this->assertCanManage($actor, $subject);

        return $this->mutate($subject, static fn (WardrobeOnboarding $onboarding) => $onboarding->skip());
    }

    public function resume(User $actor, User $subject): WardrobeOnboarding
    {
        $this->assertCanManage($actor, $subject);

        return $this->mutate($subject, static fn (WardrobeOnboarding $onboarding) => $onboarding->resume());
    }

    public function refreshProgress(User $actor, User $subject): WardrobeOnboarding
    {
        $this->assertCanManage($actor, $subject);
        return $this->mutate($subject, function (WardrobeOnboarding $onboarding) use ($subject): void {
            $batchId = $onboarding->getActiveBatchId();
            if ($onboarding->getStage() === WardrobeOnboarding::STAGE_CAPSULE
                && $batchId !== null
                && $this->drafts->countsByBatch($subject, $batchId)['total'] === 0
                && $this->items->countActiveForUser($subject) >= self::MIN_ITEMS_FOR_OUTFIT
            ) {
                $onboarding->markReadyForOutfit();
            }
        });
    }

    public function complete(User $actor, User $subject): WardrobeOnboarding
    {
        $this->assertCanManage($actor, $subject);
        return $this->mutate($subject, static fn (WardrobeOnboarding $onboarding) => $onboarding->complete());
    }

    private function assertCanManage(User $actor, User $subject): void
    {
        if (!$this->families->canManage($actor, $subject)) {
            throw new AccessDeniedException('Нет доступа к онбордингу этого профиля');
        }
    }

    private function getOrCreate(User $subject): WardrobeOnboarding
    {
        $onboarding = $this->onboardings->findForSubject($subject);
        if ($onboarding !== null) {
            return $onboarding;
        }

        $onboarding = new WardrobeOnboarding($subject);
        $this->em->persist($onboarding);

        return $onboarding;
    }

    /** @param callable(WardrobeOnboarding):void $change */
    private function mutate(User $subject, callable $change): WardrobeOnboarding
    {
        return $this->em->wrapInTransaction(function () use ($subject, $change): WardrobeOnboarding {
            $managedSubject = $this->em->find(User::class, $subject->getId());
            if (!$managedSubject instanceof User) {
                throw new AccessDeniedException('Профиль больше недоступен');
            }
            $this->em->lock($managedSubject, LockMode::PESSIMISTIC_WRITE);
            $onboarding = $this->getOrCreate($managedSubject);
            $change($onboarding);
            $this->em->flush();

            return $onboarding;
        });
    }

    /** @return array{total:int,pending:int,recognized:int,failed:int} */
    private function batchCounts(User $subject, string $batchId): array
    {
        $counts = ['total' => 0, 'pending' => 0, 'recognized' => 0, 'failed' => 0];
        foreach ($this->drafts->findByBatch($subject, $batchId) as $draft) {
            if (in_array($draft->getStatus(), [WardrobeItemDraft::STATUS_ACCEPTED, WardrobeItemDraft::STATUS_REJECTED], true)) {
                continue;
            }
            $counts['total']++;
            if (array_key_exists($draft->getStatus(), $counts)) {
                $counts[$draft->getStatus()]++;
            }
        }

        return $counts;
    }

    /**
     * @param array{total:int,pending:int,recognized:int,failed:int}|null $batchCounts
     */
    private function nextAction(string $stage, bool $skipped, ?array $batchCounts): string
    {
        if ($stage === WardrobeOnboarding::STAGE_COMPLETED) {
            return 'done';
        }
        if ($skipped) {
            return 'resume';
        }
        if ($stage === WardrobeOnboarding::STAGE_OUTFIT) {
            return 'create_outfit';
        }
        if ($stage === WardrobeOnboarding::STAGE_CAPSULE && ($batchCounts['total'] ?? 0) > 0) {
            return ($batchCounts['pending'] ?? 0) > 0 ? 'resume_batch' : 'review_batch';
        }

        return 'start';
    }
}
