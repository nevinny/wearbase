<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\Wardrobe\WardrobeIngestHealthCommand;
use App\Repository\ScheduledCommandRepository;
use App\Repository\WardrobeItemDraftRepository;
use App\Service\Wardrobe\WardrobeIngestHealth;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WardrobeIngestHealthCommandTest extends TestCase
{
    public function testJsonCheckReturnsFailureForCriticalSnapshot(): void
    {
        $drafts = $this->createMock(WardrobeItemDraftRepository::class);
        $drafts->method('operationalSnapshot')->willReturn([
            'pending' => 0,
            'oldestPendingAt' => null,
            'expiredLeases' => 0,
            'failed' => 0,
            'retrying' => 0,
            'storageBytes' => 0,
        ]);
        $scheduled = $this->createMock(ScheduledCommandRepository::class);
        $scheduled->method('findWardrobeIngestWorker')->willReturn(null);
        $health = new WardrobeIngestHealth($drafts, $scheduled, '/path/that/does/not/exist');
        $tester = new CommandTester(new WardrobeIngestHealthCommand($health));

        $exit = $tester->execute(['--json' => true, '--check' => true]);
        $payload = json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame('critical', $payload['status']);
        self::assertFalse($payload['storage_writable']);
        self::assertFalse($payload['scheduler_configured']);
    }
}
