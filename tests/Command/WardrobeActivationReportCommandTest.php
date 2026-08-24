<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\Wardrobe\ActivationReportCommand;
use App\Repository\WardrobeActivationEventRepository;
use App\Service\Wardrobe\WardrobeActivationReport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WardrobeActivationReportCommandTest extends TestCase
{
    public function testOutputsMachineReadableAggregateWithoutRawEvents(): void
    {
        $events = $this->createMock(WardrobeActivationEventRepository::class);
        $events->expects(self::once())->method('findReportRows')->willReturn([]);
        $tester = new CommandTester(new ActivationReportCommand($events, new WardrobeActivationReport()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--days' => '7']));
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $payload['subjectsStarted']);
        self::assertArrayNotHasKey('events', $payload);
    }

    public function testRejectsUnboundedWindow(): void
    {
        $tester = new CommandTester(new ActivationReportCommand(
            $this->createStub(WardrobeActivationEventRepository::class),
            new WardrobeActivationReport(),
        ));

        self::assertSame(Command::INVALID, $tester->execute(['--days' => '367']));
    }
}
