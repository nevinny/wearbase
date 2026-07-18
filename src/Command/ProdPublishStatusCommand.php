<?php

declare(strict_types=1);

namespace App\Command;

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
 * Единая точка правды о публикации брендов на проде. Каталог генерируется на Mac (dev),
 * а фактическую публикацию (`new` + `publish_pending=1` → `active`, `published_at`) делает
 * дрип-кроном `app:brand:publish-tick` НА ПРОДЕ (см. PublishTickCommand) — dev-БД и прод-БД
 * поэтому расходятся по статусу, и смотреть их нужно строго на проде, а не локально.
 *
 * Два режима одного файла (паттерн SocialIngestClicksCommand):
 *  - `--json` — исполняется НА ПРОДЕ, считает метрики по локальному (= прод) соединению
 *    Doctrine и печатает в stdout РОВНО одну JSON-строку, без единого лишнего байта вывода.
 *  - без опций — исполняется на Mac: ssh на прод, запускает там же саму команду с `--json`,
 *    парсит JSON и рендерит человекочитаемый отчёт через SymfonyStyle.
 */
#[AsCommand(
    name: 'app:prod:publish-status',
    description: 'Статус публикаций на проде (что вывел дрип publish-tick) — запуск только с Mac',
)]
class ProdPublishStatusCommand extends Command
{
    private const SSH_TIMEOUT_SEC = 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(PROD_SSH_HOST)%')]
        private readonly string $sshHost,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Внутренний режим: посчитать метрики локально и напечатать JSON (запускается самой командой на проде через ssh)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('json')) {
            return $this->executeJson($output);
        }

        return $this->executeViaSsh($input, $output);
    }

    /** Режим прода: только метрики, только JSON, ничего больше в stdout (парсится Mac-стороной). */
    private function executeJson(OutputInterface $output): int
    {
        $conn = $this->em->getConnection();
        $tz = new \DateTimeZone('Europe/Moscow');
        $todayStart = (new \DateTime('now', $tz))->setTime(0, 0)->format('Y-m-d H:i:s');
        $weekAgo = (new \DateTime('now', $tz))->modify('-7 days')->setTime(0, 0)->format('Y-m-d H:i:s');

        $data = [
            'active_total' => (int) $conn->fetchOne("SELECT COUNT(*) FROM brand WHERE status='active'"),
            'published_today' => (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM brand WHERE published_at >= :start',
                ['start' => $todayStart],
            ),
            'published_7d' => (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM brand WHERE published_at >= :start',
                ['start' => $weekAgo],
            ),
            'queue_ready' => (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM brand WHERE status='new' AND publish_pending=1
                   AND (niche_status IS NULL OR niche_status<>'off')
                   AND (origin_status IS NULL OR origin_status NOT IN ('foreign','unknown'))",
            ),
            'queue_blocked_niche' => (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM brand WHERE status='new' AND publish_pending=1 AND niche_status='off'",
            ),
            'queue_blocked_origin' => (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM brand WHERE status='new' AND publish_pending=1 AND origin_status IN ('foreign','unknown')",
            ),
            'recent' => $conn->fetchAllAssociative(
                "SELECT title, slug, published_at FROM brand
                  WHERE status='active' AND published_at IS NOT NULL
                  ORDER BY published_at DESC LIMIT 15",
            ),
            'drip_health' => $this->fetchDripHealth($conn),
        ];

        $output->writeln(json_encode($data, JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }

    /** Опциональная таблица (может не существовать) — fail-soft, null при любом сбое. */
    private function fetchDripHealth(\Doctrine\DBAL\Connection $conn): ?array
    {
        try {
            $row = $conn->fetchAssociative('SELECT multiplier, updated_at FROM drip_health WHERE id=1');
        } catch (\Throwable) {
            return null;
        }

        return $row === false ? null : $row;
    }

    /** Режим Mac: ssh на прод, парсинг JSON, человекочитаемый отчёт. */
    private function executeViaSsh(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $process = new Process(
            ['ssh', $this->sshHost, 'cd wearbase.ru && php bin/console app:prod:publish-status --json'],
            timeout: self::SSH_TIMEOUT_SEC,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            $io->error(sprintf('ssh на прод (%s) завершился с кодом %d: %s', $this->sshHost, $process->getExitCode(), trim($process->getErrorOutput())));
            return Command::FAILURE;
        }

        $raw = trim($process->getOutput());
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $io->error('Не удалось разобрать JSON от прода: ' . mb_substr($raw, 0, 500));
            return Command::FAILURE;
        }

        $io->title('Статус публикаций на проде (' . $this->sshHost . ')');
        $io->table(
            ['Метрика', 'Значение'],
            [
                ['Активных всего', $data['active_total'] ?? '—'],
                ['Опубликовано сегодня (МСК)', $data['published_today'] ?? '—'],
                ['Опубликовано за 7 дней', $data['published_7d'] ?? '—'],
                ['В очереди дрипа', $data['queue_ready'] ?? '—'],
                ['Заблок. нишей', $data['queue_blocked_niche'] ?? '—'],
                ['Заблок. origin', $data['queue_blocked_origin'] ?? '—'],
            ],
        );

        $health = $data['drip_health'] ?? null;
        if (is_array($health) && isset($health['multiplier'])) {
            $io->text(sprintf('Дрип-множитель: ×%.2f (обновлён %s)', (float) $health['multiplier'], $health['updated_at'] ?? '—'));
        } else {
            $io->text('Дрип-множитель: нет данных');
        }

        $recent = is_array($data['recent'] ?? null) ? $data['recent'] : [];
        $rows = array_map(
            static fn (array $r) => [$r['title'] ?? '—', $r['slug'] ?? '—', $r['published_at'] ?? '—'],
            $recent,
        );
        $io->section('Последние опубликованные (до 15)');
        $io->table(['Название', 'Slug', 'Опубликован'], $rows);

        return Command::SUCCESS;
    }
}
