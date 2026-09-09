<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ScheduledCommand;
use App\Entity\WardrobeItemDraft;
use App\Repository\ScheduledCommandRepository;
use App\Repository\WardrobeItemDraftRepository;
use App\Tests\Controller\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WardrobeIngestHealthRepositoryTest extends KernelTestCase
{
    public function testOperationalSnapshotUsesPersistedQueueState(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $user = UserFactory::withEmail($container, 'ingest-health-repository@test.local');
        $now = new \DateTimeImmutable();
        /** @var WardrobeItemDraftRepository $drafts */
        $drafts = $container->get(WardrobeItemDraftRepository::class);
        $before = $drafts->operationalSnapshot($now);

        $pending = (new WardrobeItemDraft())->setUser($user)->setBatchId('health-pending')->setPhoto('pending.jpg')->setFileSize(100);
        $retrying = (new WardrobeItemDraft())->setUser($user)->setBatchId('health-retry')->setPhoto('retry.jpg')->setFileSize(200);
        $retrying->claim('worker', $now->modify('+5 minutes'));
        $retrying->releaseForRetry('temporary');
        $expired = (new WardrobeItemDraft())->setUser($user)->setBatchId('health-expired')->setPhoto('expired.jpg')->setFileSize(300);
        $expired->claim('dead-worker', $now->modify('-1 minute'));
        $failed = (new WardrobeItemDraft())->setUser($user)->setBatchId('health-failed')->setStatus(WardrobeItemDraft::STATUS_FAILED);
        foreach ([$pending, $retrying, $expired, $failed] as $draft) {
            $em->persist($draft);
        }
        /** @var ScheduledCommandRepository $scheduled */
        $scheduled = $container->get(ScheduledCommandRepository::class);
        $job = $scheduled->findWardrobeIngestWorker();
        if ($job === null) {
            $job = (new ScheduledCommand())->setEnvironment('prod')->setName('ingest')
                ->setCommand('app:wardrobe:ingest-drafts --no-debug')->setSchedule('*/2 * * * *');
            $em->persist($job);
        }
        $em->flush();

        $snapshot = $drafts->operationalSnapshot($now);

        self::assertSame($before['pending'] + 2, $snapshot['pending']);
        self::assertSame($before['retrying'] + 1, $snapshot['retrying']);
        self::assertSame($before['expiredLeases'] + 1, $snapshot['expiredLeases']);
        self::assertSame($before['failed'] + 1, $snapshot['failed']);
        self::assertSame($before['storageBytes'] + 600, $snapshot['storageBytes']);
        self::assertNotNull($snapshot['oldestPendingAt']);
        self::assertSame($job->getId(), $scheduled->findWardrobeIngestWorker()?->getId());
    }
}
