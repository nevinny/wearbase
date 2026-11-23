<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Notification\AdminNotifier;
use App\Notification\MorningDigestInbox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class MorningDigestInboxTest extends TestCase
{
    private string $dir;
    /** @var list<string> */
    private array $sent = [];
    private bool $sendResult = true;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/digest-inbox-' . bin2hex(random_bytes(4));
        $this->sent = [];
        $this->sendResult = true;
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function inbox(string $now = '2026-11-23 06:00:00 UTC'): MorningDigestInbox
    {
        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->method('sendChecked')->willReturnCallback(function (string $html): bool {
            $this->sent[] = $html;

            return $this->sendResult;
        });

        return new MorningDigestInbox($notifier, new MockClock($now), $this->dir);
    }

    public function testSectionsSortedByNumericOrderAndSameSourceOverwrites(): void
    {
        $inbox = $this->inbox();
        $inbox->add('weekly', 'W', 100);
        $inbox->add('advisor', 'A-old', 10);
        $inbox->add('competitor', 'C', 20);
        $inbox->add('advisor', 'A-new', 15); // тот же source с другим order — заменяет

        $this->assertSame(['A-new', 'C', 'W'], $inbox->sections());
        $this->assertSame([], $this->sent, 'до отправки дайджеста ничего не уходит в TG');
        $this->assertDirectoryExists($this->dir . '/2026-11-23');
    }

    public function testMoscowDateDecidesTheDay(): void
    {
        // 22:30 UTC 22.11 = 01:30 МСК 23.11
        $inbox = $this->inbox('2026-11-22 22:30:00 UTC');
        $inbox->add('x', 'X', 1);

        $this->assertDirectoryExists($this->dir . '/2026-11-23');
    }

    public function testLateContributorAfterMarkSentGoesDirect(): void
    {
        $inbox = $this->inbox();
        $inbox->add('advisor', 'A', 10);
        $this->assertFalse($inbox->isSent());

        $inbox->markSent();
        $this->assertTrue($inbox->isSent());

        $this->assertTrue($inbox->add('late', 'L', 50));
        $this->assertSame(['L'], $this->sent);
        $this->assertSame(['A'], $inbox->sections(), 'опоздавший не пишется в уже отправленный день');

        $this->sendResult = false;
        $this->assertFalse($inbox->add('late2', 'L2', 60), 'сбой прямой отправки виден вызывающему');
    }

    public function testNextDayStartsEmptyAndOldDaysPruned(): void
    {
        $this->inbox('2026-11-10 06:00:00 UTC')->add('old', 'O', 1);
        $this->inbox('2026-11-20 06:00:00 UTC')->add('recent', 'R', 1);

        $today = $this->inbox('2026-11-23 06:00:00 UTC');
        $this->assertSame([], $today->sections());
        $this->assertFalse($today->isSent());
        $today->markSent();

        $this->assertDirectoryDoesNotExist($this->dir . '/2026-11-10');
        $this->assertDirectoryExists($this->dir . '/2026-11-20');
    }

    public function testPackKeepsSectionsWholeWithinLimit(): void
    {
        $a = str_repeat('a', 30);
        $b = str_repeat('b', 30);
        $c = str_repeat('c', 30);

        $this->assertSame(["$a\n\n$b", $c], MorningDigestInbox::pack([$a, $b, $c], 70));
        $this->assertSame(["$a\n\n$b\n\n$c"], MorningDigestInbox::pack([$a, $b, $c], 100));
        $this->assertSame([], MorningDigestInbox::pack([]));
    }

    public function testPackSplitsOversizedSectionOnLineBoundariesNotInsideTags(): void
    {
        $lines = [];
        for ($i = 0; $i < 10; $i++) {
            $lines[] = sprintf('<b>строка %d</b> — <i>%s</i>', $i, str_repeat('ж', 20));
        }
        $section = "<b>Заголовок</b>\n" . implode("\n", $lines);

        $messages = MorningDigestInbox::pack(['<b>ядро</b>', $section], 120);

        $this->assertGreaterThan(2, count($messages));
        foreach ($messages as $m) {
            $this->assertLessThanOrEqual(120, mb_strlen($m));
            $this->assertSame(substr_count($m, '<b>'), substr_count($m, '</b>'), "теги сбалансированы: $m");
            $this->assertSame(substr_count($m, '<i>'), substr_count($m, '</i>'), "теги сбалансированы: $m");
        }
        $this->assertSame(
            str_replace("\n\n", "\n", '<b>ядро</b>' . "\n" . $section),
            str_replace("\n\n", "\n", implode("\n", $messages)),
            'ничего не потеряно',
        );
    }

    public function testPackSingleHugeLineFallsBackToPlainText(): void
    {
        $messages = MorningDigestInbox::pack(['<b>' . str_repeat('x', 200) . '</b>'], 50);

        $this->assertCount(1, $messages);
        $this->assertSame(50, mb_strlen($messages[0]));
        $this->assertStringNotContainsString('<', $messages[0]);
    }
}
