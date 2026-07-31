<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RunScheduledCommandsCommand;
use App\Entity\ScheduledCommand;
use App\Repository\ScheduledCommandRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Регрессия на дефект от 2026-08-01: задача, чья минута расписания попала в «дыру» тиков,
 * не запускалась НИКОГДА. Диспетчер держит глобальный лок на время джоба, а `app:gsc:sync`
 * не влезал в свой timeout и занимал 08:00–09:00 целиком → каждый тик этого часа печатал
 * «Пропуск: предыдущий проход ещё выполняется», а `isDue()` совпадает только в свою минуту
 * и второго шанса не даёт. Итог: `app:advisor:snapshot` (50 8 * * *) молчал 13 дней,
 * `app:social:evaluate` (0 9 * * 1) — с 13 июля.
 *
 * Проверяем догон по next_run_at через `--dry-run`: это тот же путь отбора due-задач,
 * но без лока и без запуска процессов.
 */
class RunScheduledCommandsCommandTest extends TestCase
{
    /** Расписание на минуту, которая заведомо не наступает сейчас (текущее время + 5 минут). */
    private function scheduleNotDueNow(): string
    {
        $future = (new \DateTime('now', new \DateTimeZone('Europe/Moscow')))->modify('+5 minutes');

        return sprintf('%d %d * * *', (int) $future->format('i'), (int) $future->format('G'));
    }

    /**
     * Слот так, как его отдаёт гидрация Doctrine: цифры МОСКОВСКОГО wall-time с ярлыком
     * дефолтной зоны PHP (здесь UTC, разница 3 часа). Диспетчер обязан сравнивать wall-time,
     * а не абсолютные моменты — иначе «через 3 часа» прочитается как «уже пора».
     */
    private function slot(string $shift): \DateTime
    {
        $moscow = (new \DateTime('now', new \DateTimeZone('Europe/Moscow')))->modify($shift);

        return new \DateTime($moscow->format('Y-m-d H:i:s'));
    }

    private function makeTask(string $schedule, ?\DateTimeInterface $nextRunAt): ScheduledCommand
    {
        $task = (new ScheduledCommand())
            ->setEnvironment('dev')
            ->setName('Тестовая задача')
            ->setCommand('app:test:noop')
            ->setSchedule($schedule);
        $task->setNextRunAt($nextRunAt);

        return $task;
    }

    private function runDispatcher(ScheduledCommand $task): string
    {
        $repository = $this->createMock(ScheduledCommandRepository::class);
        $repository->method('findEnabled')->willReturn([$task]);

        $command = new RunScheduledCommandsCommand(
            $repository,
            $this->createMock(EntityManagerInterface::class),
            sys_get_temp_dir(),
            'dev',
        );
        (new Application())->add($command);

        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true]);

        return $tester->getDisplay();
    }

    public function testПропущенныйСлотДогоняется(): void
    {
        $task = $this->makeTask($this->scheduleNotDueNow(), $this->slot('-3 hours'));

        self::assertStringContainsString('app:test:noop', $this->runDispatcher($task),
            'next_run_at в прошлом = слот съеден чужим локом, задачу надо догнать');
    }

    public function testЗадачаСБудущимСлотомНеЗапускается(): void
    {
        $task = $this->makeTask($this->scheduleNotDueNow(), $this->slot('+3 hours'));

        self::assertStringNotContainsString('app:test:noop', $this->runDispatcher($task));
    }

    public function testНикогдаНеЗапускавшаясяЗадачаЖдётСвоейМинуты(): void
    {
        // next_run_at = NULL: истории нет, догонять нечего — иначе недельная задача
        // выстрелила бы сразу после появления в админке.
        $task = $this->makeTask($this->scheduleNotDueNow(), null);

        self::assertStringNotContainsString('app:test:noop', $this->runDispatcher($task));
    }

    public function testБитоеРасписаниеНеРоняетТик(): void
    {
        $task = $this->makeTask('не расписание', $this->slot('-3 hours'));

        $display = $this->runDispatcher($task);
        self::assertStringNotContainsString('[due]', $display);
        self::assertStringContainsString('Битое расписание', $display);
    }
}
