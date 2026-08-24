<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\Wardrobe\IngestWardrobeDraftsCommand;
use App\Entity\User;
use App\Entity\WardrobeItemDraft;
use App\Repository\WardrobeItemDraftRepository;
use App\Repository\WardrobeActivationEventRepository;
use App\Entity\WardrobeActivationEvent;
use App\Service\Wardrobe\WardrobeActivationService;
use App\Service\Wardrobe\WardrobeAiService;
use App\Service\WardrobeAiMeter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Vich\UploaderBundle\Storage\StorageInterface;

final class IngestWardrobeDraftsActivationTest extends TestCase
{
    public function testRecordsBatchStartAndCompletionOnceAroundClaimedWork(): void
    {
        $actor = $this->user(1);
        $subject = $this->user(2);
        $draft = (new WardrobeItemDraft())->setActor($actor)->setProfileSubject($subject)->setBatchId('test-batch');
        (new \ReflectionProperty(WardrobeItemDraft::class, 'id'))->setValue($draft, 10);

        $drafts = $this->createMock(WardrobeItemDraftRepository::class);
        $drafts->method('claimPending')->willReturn([$draft]);
        $drafts->expects(self::once())->method('finishClaim')->willReturn(true);
        $drafts->method('countsByBatch')->willReturn(['total' => 1, 'pending' => 0, 'recognized' => 0, 'failed' => 1]);
        $events = $this->createMock(WardrobeActivationEventRepository::class);
        $recorded = [];
        $events->expects(self::exactly(2))->method('recordOnce')->willReturnCallback(
            static function (User $eventSubject, string $event, string $key, array $metadata) use (&$recorded, $subject): bool {
                self::assertSame($subject, $eventSubject);
                self::assertSame(hash('sha256', 'test-batch'), $key);
                self::assertSame(['actorKind' => 'family_manager', 'entryPoint' => 'batch'], $metadata);
                $recorded[] = $event;
                return true;
            },
        );
        $activation = new WardrobeActivationService($events);
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('resolvePath')->willReturn(null);
        $meter = $this->createStub(WardrobeAiMeter::class);
        $meter->method('allowed')->willReturn(true);
        $projectDir = sys_get_temp_dir().'/wardrobe_activation_'.bin2hex(random_bytes(4));
        mkdir($projectDir.'/var', 0777, true);

        try {
            $command = new IngestWardrobeDraftsCommand(
                $drafts,
                $this->createStub(WardrobeAiService::class),
                $storage,
                $meter,
                $projectDir,
                $activation,
            );
            self::assertSame(Command::SUCCESS, (new CommandTester($command))->execute([]));
            self::assertSame([
                WardrobeActivationEvent::BATCH_RECOGNITION_STARTED,
                WardrobeActivationEvent::BATCH_RECOGNITION_COMPLETED,
            ], $recorded);
        } finally {
            @unlink($projectDir.'/var/wardrobe_ingest_drafts.lock');
            @rmdir($projectDir.'/var');
            @rmdir($projectDir);
        }
    }

    private function user(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        return $user;
    }
}
