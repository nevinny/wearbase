<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeActivationEvent;
use App\Repository\WardrobeActivationEventRepository;
use App\Service\Wardrobe\WardrobeActivationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WardrobeActivationServiceTest extends TestCase
{
    public function testRecordsOnlyAllowlistedLowCardinalityMetadata(): void
    {
        $actor = $this->userWithId(10);
        $subject = $this->userWithId(20);
        $events = $this->createMock(WardrobeActivationEventRepository::class);
        $events->expects(self::once())->method('recordOnce')->with(
            $subject,
            WardrobeActivationEvent::FIRST_ITEM_ADDED,
            WardrobeActivationEvent::FIRST_ITEM_ADDED,
            ['actorKind' => 'family_manager', 'entryPoint' => 'batch'],
        );

        (new WardrobeActivationService($events))->firstItemAdded($actor, $subject, 'batch');
    }

    public function testRejectsMetadataOutsideAllowlist(): void
    {
        $events = $this->createStub(WardrobeActivationEventRepository::class);
        $service = new WardrobeActivationService($events);

        $this->expectException(\InvalidArgumentException::class);
        $service->firstItemAdded($this->userWithId(10), $this->userWithId(10), 'https://example.test/private');
    }

    #[DataProvider('milestoneProvider')]
    public function testMilestoneContract(string $method, string $eventType, string $entryPoint): void
    {
        $subject = $this->userWithId(10);
        $events = $this->createMock(WardrobeActivationEventRepository::class);
        $events->expects(self::once())->method('recordOnce')->with(
            $subject,
            $eventType,
            $eventType,
            ['actorKind' => 'self', 'entryPoint' => $entryPoint],
        );
        $service = new WardrobeActivationService($events);

        if (in_array($method, ['firstItemAdded', 'repeatWearRecorded'], true)) {
            $service->{$method}($subject, $subject, $entryPoint);
        } else {
            $service->{$method}($subject, $subject);
        }
    }

    public function testDraftMetadataIsBoundedAndDedupKeyIsHashed(): void
    {
        $subject = $this->userWithId(10);
        $events = $this->createMock(WardrobeActivationEventRepository::class);
        $events->expects(self::once())->method('recordOnce')->with(
            $subject,
            WardrobeActivationEvent::DRAFT_ACCEPTED,
            hash('sha256', '42'),
            [
                'actorKind' => 'self',
                'entryPoint' => 'batch',
                'source' => 'manual_correction',
                'durationBucket' => '1_5m',
                'correction' => true,
                'autofillAccepted' => false,
            ],
        );

        (new WardrobeActivationService($events))->draftAccepted($subject, $subject, 42, 'manual_correction', '1_5m', true, false);
    }

    public function testRejectsFreeTextDraftMetadata(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WardrobeActivationService($this->createStub(WardrobeActivationEventRepository::class)))
            ->draftAccepted($this->userWithId(10), $this->userWithId(10), 42, 'email@example.test', '1_5m', true, false);
    }

    /** @return iterable<string, array{string,string,string}> */
    public static function milestoneProvider(): iterable
    {
        yield 'onboarding' => ['onboardingStarted', WardrobeActivationEvent::ONBOARDING_STARTED, 'batch'];
        yield 'first item' => ['firstItemAdded', WardrobeActivationEvent::FIRST_ITEM_ADDED, 'manual'];
        yield 'first outfit' => ['firstOutfitCreated', WardrobeActivationEvent::FIRST_OUTFIT_CREATED, 'stylist'];
        yield 'repeat wear' => ['repeatWearRecorded', WardrobeActivationEvent::REPEAT_WEAR_RECORDED, 'outfit'];
    }

    private function userWithId(int $id): User
    {
        $user = new User();
        $property = new \ReflectionProperty(User::class, 'id');
        $property->setValue($user, $id);

        return $user;
    }
}
