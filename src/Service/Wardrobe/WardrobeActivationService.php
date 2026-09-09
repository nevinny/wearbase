<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeActivationEvent;
use App\Repository\WardrobeActivationEventRepository;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;

final class WardrobeActivationService
{
    private const ENTRY_POINTS = ['batch', 'manual', 'purchase', 'stylist', 'wear_review', 'outfit'];

    public function __construct(
        private readonly WardrobeActivationEventRepository $events,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function onboardingStarted(User $actor, User $subject): void
    {
        $this->record($actor, $subject, WardrobeActivationEvent::ONBOARDING_STARTED, 'batch');
    }

    public function firstItemAdded(User $actor, User $subject, string $entryPoint): void
    {
        $this->record($actor, $subject, WardrobeActivationEvent::FIRST_ITEM_ADDED, $entryPoint);
    }

    public function firstOutfitCreated(User $actor, User $subject): void
    {
        $this->record($actor, $subject, WardrobeActivationEvent::FIRST_OUTFIT_CREATED, 'stylist');
    }

    public function repeatWearRecorded(User $actor, User $subject, string $entryPoint): void
    {
        $this->record($actor, $subject, WardrobeActivationEvent::REPEAT_WEAR_RECORDED, $entryPoint);
    }

    public function batchRecognitionStarted(User $actor, User $subject, string $batchId): void
    {
        $this->record($actor, $subject, WardrobeActivationEvent::BATCH_RECOGNITION_STARTED, 'batch', $this->hash($batchId));
    }

    public function batchRecognitionCompleted(User $actor, User $subject, string $batchId): void
    {
        $this->record($actor, $subject, WardrobeActivationEvent::BATCH_RECOGNITION_COMPLETED, 'batch', $this->hash($batchId));
    }

    public function draftAccepted(User $actor, User $subject, int $draftId, string $source, string $durationBucket, bool $correction, bool $autofillAccepted): void
    {
        if (!in_array($source, ['ai', 'manual_correction'], true)
            || !in_array($durationBucket, ['under_1m', '1_5m', '5_15m', 'over_15m'], true)
        ) {
            throw new \InvalidArgumentException('Unknown draft activation metadata');
        }
        $this->record($actor, $subject, WardrobeActivationEvent::DRAFT_ACCEPTED, 'batch', $this->hash((string) $draftId), [
            'source' => $source,
            'durationBucket' => $durationBucket,
            'correction' => $correction,
            'autofillAccepted' => $autofillAccepted,
        ]);
    }

    /** @param array<string, bool|string> $extra */
    private function record(User $actor, User $subject, string $eventType, string $entryPoint, ?string $dedupKey = null, array $extra = []): void
    {
        if (!in_array($entryPoint, self::ENTRY_POINTS, true)) {
            throw new \InvalidArgumentException('Unknown activation entry point');
        }

        try {
            $this->events->recordOnce($subject, $eventType, $dedupKey ?? $eventType, [
                'actorKind' => $actor->getId() === $subject->getId() ? 'self' : 'family_manager',
                'entryPoint' => $entryPoint,
                ...$extra,
            ]);
        } catch (Exception $exception) {
            // Product telemetry must never roll back a successful wardrobe action.
            $this->logger?->error('Wardrobe activation event was not recorded', [
                'event_type' => $eventType,
                'exception' => $exception,
            ]);
        }
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
