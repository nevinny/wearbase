<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\WardrobeItemDraft;
use PHPUnit\Framework\TestCase;

final class WardrobeItemDraftLeaseTest extends TestCase
{
    public function testClaimAndRetryTrackAttempts(): void
    {
        $draft = new WardrobeItemDraft();
        $draft->claim('worker-a', new \DateTimeImmutable('+5 minutes'));

        self::assertSame(WardrobeItemDraft::STATUS_PROCESSING, $draft->getStatus());
        self::assertSame('worker-a', $draft->getWorkerId());
        self::assertSame(1, $draft->getAttempts());

        $draft->releaseForRetry('timeout');
        self::assertSame(WardrobeItemDraft::STATUS_PENDING, $draft->getStatus());
        self::assertNull($draft->getLeaseUntil());

        $draft->claim('worker-b', new \DateTimeImmutable('+5 minutes'));
        self::assertSame(2, $draft->getAttempts());
        $draft->finishProcessing(WardrobeItemDraft::STATUS_RECOGNIZED);
        self::assertSame(WardrobeItemDraft::STATUS_RECOGNIZED, $draft->getStatus());
    }

    public function testActiveLeaseCannotBeClaimedTwice(): void
    {
        $draft = new WardrobeItemDraft();
        $draft->claim('worker-a', new \DateTimeImmutable('+5 minutes'));

        $this->expectException(\DomainException::class);
        $draft->claim('worker-b', new \DateTimeImmutable('+5 minutes'));
    }
}
