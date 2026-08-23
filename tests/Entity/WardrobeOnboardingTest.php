<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\WardrobeOnboarding;
use PHPUnit\Framework\TestCase;

final class WardrobeOnboardingTest extends TestCase
{
    public function testSkipAndResumeKeepExactStage(): void
    {
        $onboarding = new WardrobeOnboarding(new User());
        $batch = '11111111-1111-4111-8111-111111111111';

        $onboarding->startCapsule($batch);
        $onboarding->skip();

        self::assertTrue($onboarding->isSkipped());
        self::assertSame(WardrobeOnboarding::STAGE_CAPSULE, $onboarding->getStage());
        self::assertSame($batch, $onboarding->getActiveBatchId());

        $onboarding->resume();
        self::assertFalse($onboarding->isSkipped());
        self::assertSame(WardrobeOnboarding::STAGE_CAPSULE, $onboarding->getStage());
    }

    public function testCompletedOnboardingCannotStartAnotherCapsule(): void
    {
        $onboarding = new WardrobeOnboarding(new User());
        $onboarding->complete();

        self::assertTrue($onboarding->isCompleted());
        $this->expectException(\DomainException::class);
        $onboarding->startCapsule('11111111-1111-4111-8111-111111111111');
    }

    public function testInvalidBatchIdentifierIsRejected(): void
    {
        $onboarding = new WardrobeOnboarding(new User());

        $this->expectException(\InvalidArgumentException::class);
        $onboarding->startCapsule('../foreign-batch');
    }
}
