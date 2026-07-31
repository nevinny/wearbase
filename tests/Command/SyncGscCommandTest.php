<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SyncGscCommand;
use App\Notification\AdminNotifier;
use App\Service\Gsc\GscClient;
use App\Service\PageClassifier;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Регрессия на дефект от 2026-08-01: прогон не влезал в timeout крона (3600с), потому что
 * фаза инспекции упирается не в квоту Google, а в латентность URL Inspection (6.9с на URL
 * × 625 молчунов ≈ 72 минуты). Команду убивали → report() не доходил, а диспетчер крона
 * (глобальный лок) весь этот час пропускал тики и терял соседние задачи.
 *
 * Проверяем именно предохранитель — что исчерпанный бюджет сворачивает фазу, НЕ дёргая
 * инспекцию: непроверенный предохранитель ровно этим дефектом и обернулся.
 */
class SyncGscCommandTest extends TestCase
{
    private function runInspection(GscClient $gsc, string $budget): string
    {
        $db = $this->createMock(Connection::class);
        // Обе очереди инспекции (свежие + молчуны) и множество «уже с показами».
        $db->method('fetchAllAssociative')->willReturn([['id' => 1, 'slug' => 'test-brand']]);
        $db->method('fetchFirstColumn')->willReturn([]);
        $db->method('fetchOne')->willReturn(0);

        $command = new SyncGscCommand($gsc, $db, $this->createMock(PageClassifier::class), $this->createMock(AdminNotifier::class));
        (new Application())->add($command);

        $tester = new CommandTester($command);
        $tester->execute(['--inspect-only' => true, '--inspect-budget' => $budget]);

        return $tester->getDisplay();
    }

    public function testИсчерпанныйБюджетСворачиваетИнспекцию(): void
    {
        $gsc = $this->createMock(GscClient::class);
        $gsc->method('isConfigured')->willReturn(true);
        $gsc->expects(self::never())->method('inspectUrl');

        self::assertStringContainsString('Бюджет инспекции 0с исчерпан', $this->runInspection($gsc, '0'));
    }

    public function testСБюджетомОчередьИнспектируется(): void
    {
        $gsc = $this->createMock(GscClient::class);
        $gsc->method('isConfigured')->willReturn(true);
        $gsc->expects(self::once())->method('inspectUrl')
            ->with('https://wearbase.ru/ru/brands/test-brand')
            ->willReturn(['indexed' => true, 'coverageState' => 'Submitted and indexed']);

        $display = $this->runInspection($gsc, '900');
        self::assertStringNotContainsString('Бюджет инспекции', $display);
        self::assertStringContainsString('Проверено: 1', $display);
    }
}
