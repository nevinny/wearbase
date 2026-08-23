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
}
