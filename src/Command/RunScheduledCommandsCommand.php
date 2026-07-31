<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ScheduledCommand;
use App\Repository\ScheduledCommandRepository;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Единая точка входа крона: launchd тикает раз в минуту → эта команда смотрит таблицу
 * `scheduled_command` и запускает те задачи, чьё время наступило (CronExpression::isDue).
 *
 * Один launchd-plist на всю систему; добавление/выключение задач — из админки.
 * Тяжёлые батчи (RAG на сотни брендов) сюда НЕ ставим — их по-прежнему гоняем nohup'ом,
 * иначе долгий джоб задержит остальные внутри тика.
 *
 *   php bin/console app:cron:run-scheduled            # один проход (так зовёт launchd)
 *   php bin/console app:cron:run-scheduled --dry-run  # показать, что сейчас due, без запуска
 */
#[AsCommand(name: 'app:cron:run-scheduled', description: 'Запустить запланированные команды, чьё время наступило')]
class RunScheduledCommandsCommand extends Command
{
    /** Расписания трактуем по московскому времени (как делал прежний Mac-crontab в локальной зоне). */
    private const TZ = 'Europe/Moscow';

    public function __construct(
        private readonly ScheduledCommandRepository $repository,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        // Окружение этой машины: dev (Mac) | prod | llm (.43). Задаётся в .env.local per-host.
        #[Autowire('%env(default::CRON_ENV)%')]
        private readonly ?string $cronEnv = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать due-команды, ничего не запуская');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $now    = new \DateTime('now', new \DateTimeZone(self::TZ));
        $env    = trim((string) $this->cronEnv) !== '' ? trim((string) $this->cronEnv) : 'dev';

        // Глобальный лок: не даём тикам наложиться, если предыдущий проход ещё идёт.
        $lockHandle = $dryRun ? false : $this->acquireLock();
        if (!$dryRun && $lockHandle === false) {
            $io->writeln(sprintf('[%s] Пропуск: предыдущий проход ещё выполняется', $now->format('H:i:s')));

            return Command::SUCCESS;
        }

        try {
            foreach ($this->repository->findEnabled($env) as $cmd) {
                if (!$this->isDue($cmd, $now, $io)) {
                    continue;
                }

                if ($dryRun) {
                    $io->writeln(sprintf('[due][%s] %s — <info>%s</info>', $env, $cmd->getName(), $cmd->getCommand()));
                    continue;
                }

                if ($this->isStillRunning($cmd, $now)) {
                    $io->writeln(sprintf('[%s] Ещё выполняется, пропуск: %s', $now->format('H:i:s'), $cmd->getCommand()));
                    continue;
                }

                $this->dispatch($cmd, $io);
            }
        } finally {
            if (\is_resource($lockHandle)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Due = наступила минута расписания ЛИБО слот был пропущен и мы его догоняем.
     *
     * Догон обязателен: isDue() совпадает только в свою минуту, а тик может не случиться —
     * долгий джоб держит глобальный лок («Пропуск: предыдущий проход ещё выполняется»),
     * или Mac спал. Тогда задача, чья минута попала в дыру, не запускалась НИКОГДА:
     * app:gsc:sync висел 08:00–09:00 и 13 дней подряд съедал app:advisor:snapshot (50 8 * * *),
     * app:kb:sync-tg (30 8 * * *) и весь остальной час.
     *
     * next_run_at в прошлом = слот пропущен. Сравниваем как строки 'Y-m-d H:i': в БД лежит
     * московское wall-time (его пишет dispatch()), а гидрация даёт DateTime в дефолтной зоне
     * PHP — сравнение объектов уехало бы на разницу зон.
     */
    private function isDue(ScheduledCommand $cmd, \DateTime $now, SymfonyStyle $io): bool
    {
        try {
            if ((new CronExpression($cmd->getSchedule()))->isDue($now)) {
                return true;
            }
        } catch (\Throwable $e) {
            $io->warning(sprintf('Битое расписание «%s» у «%s»: %s', $cmd->getSchedule(), $cmd->getCommand(), $e->getMessage()));

            return false;
        }

        $next = $cmd->getNextRunAt();

        return $next !== null && $next->format('Y-m-d H:i') <= $now->format('Y-m-d H:i');
    }

    /** Считаем задачу зависшей, если она «выполняется» дольше своего таймаута — тогда перезапускаем. */
    private function isStillRunning(ScheduledCommand $cmd, \DateTime $now): bool
    {
        $since = $cmd->getRunningSince();
        if ($since === null) {
            return false;
        }

        return ($now->getTimestamp() - $since->getTimestamp()) < $cmd->getTimeoutSec();
    }

    private function dispatch(ScheduledCommand $cmd, SymfonyStyle $io): void
    {
        $startedAt = new \DateTime('now', new \DateTimeZone(self::TZ));
        $io->writeln(sprintf('[%s] Запуск: %s', $startedAt->format('H:i:s'), $cmd->getCommand()));

        $cmd->setRunningSince($startedAt);
        $this->em->flush();

        $process = Process::fromShellCommandline(
            \PHP_BINARY . ' -d memory_limit=512M bin/console ' . $cmd->getCommand(),
            $this->projectDir,
            timeout: (float) $cmd->getTimeoutSec(),
        );

        $exitCode = null;
        try {
            $exitCode = $process->run();
            $tail = trim($process->getOutput() . "\n" . $process->getErrorOutput());
        } catch (\Throwable $e) {
            $tail = 'EXCEPTION: ' . $e->getMessage();
        }

        $finishedAt = new \DateTime('now', new \DateTimeZone(self::TZ));
        $cmd->setRunningSince(null);
        $cmd->setLastRunAt($startedAt);
        $cmd->setLastExitCode($exitCode);
        $cmd->setLastDurationSec($finishedAt->getTimestamp() - $startedAt->getTimestamp());
        $cmd->setLastOutput(mb_substr($tail, -4000) ?: null);
        try {
            $cmd->setNextRunAt((new CronExpression($cmd->getSchedule()))->getNextRunDate($finishedAt));
        } catch (\Throwable) {
            // битое расписание уже отловлено в isDue() — сюда не дойдём, но на всякий случай не падаем
        }
        $this->em->flush();

        $io->writeln(sprintf('[%s] Готово (%ds, exit %s): %s',
            $finishedAt->format('H:i:s'),
            $cmd->getLastDurationSec(),
            $exitCode === null ? 'ERR' : $exitCode,
            $cmd->getCommand(),
        ));
    }

    /** @return resource|false открытый дескриптор с захваченным LOCK_EX, либо false если занято */
    private function acquireLock(): mixed
    {
        $handle = fopen($this->projectDir . '/var/cron-run-scheduled.lock', 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        return $handle;
    }
}
