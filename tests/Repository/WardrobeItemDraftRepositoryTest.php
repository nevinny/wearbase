<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Entity\WardrobeItemDraft;
use App\Repository\WardrobeItemDraftRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WardrobeItemDraftRepositoryTest extends KernelTestCase
{
    public function testClaimPendingDoesNotReturnActiveLeaseToSecondWorker(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repo = self::getContainer()->get(WardrobeItemDraftRepository::class);
        $em->beginTransaction();

        try {
            $user = (new User())
                ->setEmail('draft-lease-'.uniqid().'@test.local')
                ->setPassword('test')
                ->setRoles(['ROLE_CUSTOMER']);
            $batch = 'lease-'.uniqid();
            $first = (new WardrobeItemDraft())->setUser($user)->setBatchId($batch);
            $second = (new WardrobeItemDraft())->setUser($user)->setBatchId($batch);
            $em->persist($user);
            $em->persist($first);
            $em->persist($second);
            $em->flush();

            $claimedA = $repo->claimPending(1, 'worker-a', $batch);
            $claimedB = $repo->claimPending(1, 'worker-b', $batch);

            self::assertSame([$first->getId()], array_map(static fn (WardrobeItemDraft $draft): ?int => $draft->getId(), $claimedA));
            self::assertSame([$second->getId()], array_map(static fn (WardrobeItemDraft $draft): ?int => $draft->getId(), $claimedB));
            self::assertSame('worker-a', $first->getWorkerId());
            self::assertSame('worker-b', $second->getWorkerId());
        } finally {
            $em->rollback();
        }
    }

    public function testStaleWorkerCannotFinishOrReleaseReclaimedDraft(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repo = self::getContainer()->get(WardrobeItemDraftRepository::class);
        $em->beginTransaction();

        try {
            $user = (new User())
                ->setEmail('draft-fencing-'.uniqid().'@test.local')
                ->setPassword('test')
                ->setRoles(['ROLE_CUSTOMER']);
            $draft = (new WardrobeItemDraft())->setUser($user)->setBatchId('fencing-'.uniqid());
            $em->persist($user);
            $em->persist($draft);
            $em->flush();
            $draftId = $draft->getId();
            self::assertNotNull($draftId);

            $draft->claim('worker-a', new \DateTimeImmutable('-1 minute'));
            $em->flush();
            self::assertCount(1, $repo->claimPending(1, 'worker-b', $draft->getBatchId()));

            self::assertFalse($repo->finishClaim($draftId, 'worker-a', WardrobeItemDraft::STATUS_RECOGNIZED, ['name' => 'stale']));
            self::assertFalse($repo->releaseClaimForRetry($draftId, 'worker-a', 'stale retry'));
            self::assertTrue($repo->finishClaim($draftId, 'worker-b', WardrobeItemDraft::STATUS_RECOGNIZED, [
                'name' => 'fresh',
                'confidence' => 'high',
                'aiRaw' => ['model' => 'test'],
            ]));

            $em->clear();
            $saved = $em->find(WardrobeItemDraft::class, $draftId);
            self::assertSame(WardrobeItemDraft::STATUS_RECOGNIZED, $saved?->getStatus());
            self::assertSame('fresh', $saved?->getName());
            self::assertNull($saved?->getWorkerId());
            self::assertNull($saved?->getLeaseUntil());
        } finally {
            $em->rollback();
        }
    }

    public function testOnlyClaimOwnerCanExtendLease(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repo = self::getContainer()->get(WardrobeItemDraftRepository::class);
        $em->beginTransaction();

        try {
            $user = (new User())
                ->setEmail('draft-heartbeat-'.uniqid().'@test.local')
                ->setPassword('test')
                ->setRoles(['ROLE_CUSTOMER']);
            $draft = (new WardrobeItemDraft())->setUser($user)->setBatchId('heartbeat-'.uniqid());
            $em->persist($user);
            $em->persist($draft);
            $em->flush();
            $claimed = $repo->claimPending(1, 'worker-a', $draft->getBatchId());
            self::assertCount(1, $claimed);
            $draftId = $draft->getId();
            self::assertNotNull($draftId);

            self::assertFalse($repo->extendLease($draftId, 'worker-b'));
            self::assertTrue($repo->extendLease($draftId, 'worker-a'));
        } finally {
            $em->rollback();
        }
    }
}
