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

    private function record(User $actor, User $subject, string $eventType, string $entryPoint): void
    {
        if (!in_array($entryPoint, self::ENTRY_POINTS, true)) {
            throw new \InvalidArgumentException('Unknown activation entry point');
        }

        try {
            $this->events->recordOnce($subject, $eventType, [
                'actorKind' => $actor->getId() === $subject->getId() ? 'self' : 'family_manager',
                'entryPoint' => $entryPoint,
            ]);
        } catch (Exception $exception) {
            // Product telemetry must never roll back a successful wardrobe action.
            $this->logger?->error('Wardrobe activation event was not recorded', [
                'event_type' => $eventType,
                'exception' => $exception,
            ]);
        }
    }
}
