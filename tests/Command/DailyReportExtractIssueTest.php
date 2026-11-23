<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\DailyReportCommand;
use PHPUnit\Framework\TestCase;

/** Хвосты вывода — реальные last_output из scheduled_command (Mac, 2026-11-23). */
class DailyReportExtractIssueTest extends TestCase
{
    public function testWarningWithListBelowAndProgressBarAfter(): void
    {
        $out = <<<'TXT'
 --------------------------- --------
  Конфликт (deleted/system)   1
 --------------------------- --------

 [WARNING] Опубликованы на проде, но на Mac в deleted/system статусе —
           разобраться вручную:

 * murkafam


    0/2783 [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]   0%
  279/2783 [▓▓░░░░░░░░░░░░░░░░░░░░░░░░░░]  10%
TXT;
        $this->assertSame(
            'Опубликованы на проде, но на Mac в deleted/system статусе — разобраться вручную: murkafam',
            DailyReportCommand::extractIssue($out),
        );
    }

    public function testMultilineWarningJoinedAndClipped(): void
    {
        $out = <<<'TXT'
 [WARNING] ssh/zgrep завершились с кодом 255: Permission denied, please try
           again.
           Permission denied, please try again.
           u3042786@31.31.196.163: Permission denied (publickey,password).
TXT;
        $issue = DailyReportCommand::extractIssue($out);
        $this->assertStringStartsWith('ssh/zgrep завершились с кодом 255: Permission denied, please try again. Permission denied', $issue);
        $this->assertLessThanOrEqual(150, mb_strlen($issue));
        $this->assertStringEndsWith('…', $issue);
    }

    public function testErrorPreferredOverEarlierWarning(): void
    {
        $out = " [WARNING] мелочь\n\n [ERROR] Не удалось получить каталог с прода: Failed to connect\n         to wearbase.ru port 443\n\n [OK] x";
        $this->assertSame('Не удалось получить каталог с прода: Failed to connect to wearbase.ru port 443', DailyReportCommand::extractIssue($out));
    }

    public function testNoMarkerFallsBackToLastMeaningfulLine(): void
    {
        $this->assertSame('Fatal: connection reset', DailyReportCommand::extractIssue("start\nFatal: connection reset\n  5/10 [▓▓▓░░░]\n\n"));
        $this->assertSame('', DailyReportCommand::extractIssue(''));
    }
}
