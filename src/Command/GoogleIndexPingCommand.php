<?php

namespace App\Command;

use App\Service\Gsc\GoogleIndexingClient;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Google Indexing API — единственный Google-канал индексации (anti-trifecta,
 * docs/seo_adoption_plan.md п.3; Яндекс/Bing закрыты IndexNow из publish-tick).
 *
 * Приоритет URL карточек активных брендов (https://wearbase.ru/ru/brands/{slug}):
 *  1. свежеопубликованные дрипом (published_at за 14 дней) — главный риск неиндексации;
 *  2. остальные active по published_at DESC / id — re-ping cooldown 14 дней
 *     (нет записи в google_index_ping свежее 14 дней).
 *
 * Квота Google 200/день: cap --limit (default 180), уже отправленное СЕГОДНЯ
 * вычитается из квоты. 429/403 = квота кончилась → стоп до завтра.
 *
 * FAIL-OPEN: без кредов (GSC_CREDENTIALS_PATH) — notice и exit 0.
 * На проде кредов нет — команда для Mac, как app:gsc:sync.
 *
 *   0 7 * * * cd /path && php bin/console app:google:index-ping --no-debug >> var/log/google-index.log 2>&1
 */
#[AsCommand(
    name: 'app:google:index-ping',
    description: 'Google Indexing API: пинг карточек брендов (≤200/день, cooldown 14 дней) → google_index_ping',
)]
class GoogleIndexPingCommand extends Command
{
    private const SITE_BASE     = 'https://wearbase.ru'; // канонический хост страниц брендов
    private const DAILY_HARD_CAP = 200;                  // лимит Google Indexing API
    private const COOLDOWN_DAYS  = 14;                   // re-ping cooldown
    private const DELAY_USEC     = 1_500_000;            // 1.5 сек между запросами

    public function __construct(
        private readonly GoogleIndexingClient $indexing,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('slug',    null, InputOption::VALUE_REQUIRED, 'Пинговать только указанные slug (через запятую) — вне общего порядка published_at')
            ->addOption('limit',   null, InputOption::VALUE_REQUIRED, 'Дневной cap пингов (потолок ' . self::DAILY_HARD_CAP . ')', '180')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать выборку без отправки')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Google Indexing API · пинг карточек брендов');

        if (!$this->indexing->isConfigured()) {
            // fail-open: на проде кредов нет — это не ошибка пайплайна
            $io->note('Indexing API не настроен (GSC_CREDENTIALS_PATH) — пропускаем.');
            return Command::SUCCESS;
        }

        $limit = min(self::DAILY_HARD_CAP, max(1, (int) $input->getOption('limit')));

        // Уже отправлено сегодня — вычитаем из квоты
        $sentToday = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM google_index_ping WHERE pinged_at >= :today',
            ['today' => (new \DateTime('today'))->format('Y-m-d H:i:s')],
        );
        $quota = $limit - $sentToday;
        $io->text(sprintf('Квота: %d (limit %d, сегодня уже отправлено %d)', max(0, $quota), $limit, $sentToday));
        if ($quota <= 0) {
            $io->note('Дневная квота исчерпана — до завтра.');
            return Command::SUCCESS;
        }

        $targets = $this->selectTargets($quota, $input->getOption('slug'));
        if ($targets === []) {
            $io->note('Нет URL для пинга (все в пределах cooldown ' . self::COOLDOWN_DAYS . ' дней).');
            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $io->section(sprintf('DRY-RUN: %d URL', count($targets)));
            foreach ($targets as $brandId => $slug) {
                $io->text(sprintf('  [%d] %s/ru/brands/%s', $brandId, self::SITE_BASE, $slug));
            }
            return Command::SUCCESS;
        }

        $sent = 0;
        foreach ($targets as $brandId => $slug) {
            $url = self::SITE_BASE . '/ru/brands/' . $slug;
            try {
                $code = $this->indexing->publish($url);
            } catch (\Throwable $e) {
                $io->warning(sprintf('%s: %s — пропускаем.', $url, mb_substr($e->getMessage(), 0, 200)));
                continue;
            }

            $this->db->executeStatement(
                'INSERT INTO google_index_ping (url, brand_id, pinged_at, response_code)
                 VALUES (:url, :brand_id, NOW(), :code)
                 ON DUPLICATE KEY UPDATE pinged_at = NOW(), response_code = :code, brand_id = :brand_id',
                ['url' => $url, 'brand_id' => $brandId, 'code' => $code],
            );
            $sent++;

            if (in_array($code, [429, 403], true)) {
                $io->warning(sprintf('HTTP %d на %s — квота Indexing API исчерпана, остановка до завтра.', $code, $url));
                break;
            }
            if ($code >= 400) {
                $io->text(sprintf('  HTTP %d: %s', $code, $url));
            }

            usleep(self::DELAY_USEC);
        }

        $io->success(sprintf('Отправлено пингов: %d (всего за сегодня: %d)', $sent, $sentToday + $sent));

        return Command::SUCCESS;
    }

    /**
     * Выборка с re-ping cooldown 14 дней; дедуп по brand_id.
     *
     * @param string|null $slugs CSV slug'ов точечного пинга (SEO-фиксы вне очереди
     *                           published_at); cooldown и квота общие — опция меняет только выборку.
     *
     * @return array<int,string> brand_id => slug
     */
    private function selectTargets(int $quota, ?string $slugs = null): array
    {
        if ($slugs !== null && trim($slugs) !== '') {
            $wanted = array_values(array_filter(array_map('trim', explode(',', $slugs))));
            if ($wanted === []) {
                return [];
            }

            $rows = $this->db->fetchAllAssociative(
                "SELECT b.id, b.slug FROM brand b
                 LEFT JOIN google_index_ping p ON p.url = CONCAT(:base, '/ru/brands/', b.slug)
                 WHERE b.status = 'active' AND b.slug IN (:slugs)
                   AND (p.pinged_at IS NULL OR p.pinged_at < :cooldown)",
                [
                    'base'     => self::SITE_BASE,
                    'cooldown' => (new \DateTime(sprintf('-%d days', self::COOLDOWN_DAYS)))->format('Y-m-d H:i:s'),
                    'slugs'    => $wanted,
                ],
                ['slugs' => ArrayParameterType::STRING],
            );

            $targets = [];
            foreach ($rows as $row) {
                $targets[(int) $row['id']] = (string) $row['slug'];
            }

            return array_slice($targets, 0, $quota, preserve_keys: true);
        }

        $since = (new \DateTime(sprintf('-%d days', self::COOLDOWN_DAYS)))->format('Y-m-d H:i:s');
        $cooldown = $since; // одно и то же окно: 14 дней

        // Приоритет 1: свежеопубликованные дрипом
        $fresh = $this->db->fetchAllAssociative(
            "SELECT b.id, b.slug FROM brand b
             LEFT JOIN google_index_ping p ON p.url = CONCAT(:base, '/ru/brands/', b.slug)
             WHERE b.status = 'active' AND b.published_at >= :since
               AND (p.pinged_at IS NULL OR p.pinged_at < :cooldown)
             ORDER BY b.published_at DESC LIMIT " . $quota,
            ['base' => self::SITE_BASE, 'since' => $since, 'cooldown' => $cooldown],
        );

        // Приоритет 2: остальные active (published_at DESC, NULL в конце, затем id)
        $rest = $this->db->fetchAllAssociative(
            "SELECT b.id, b.slug FROM brand b
             LEFT JOIN google_index_ping p ON p.url = CONCAT(:base, '/ru/brands/', b.slug)
             WHERE b.status = 'active'
               AND (b.published_at IS NULL OR b.published_at < :since)
               AND (p.pinged_at IS NULL OR p.pinged_at < :cooldown)
             ORDER BY b.published_at DESC, b.id ASC LIMIT " . max(0, $quota - count($fresh)),
            ['base' => self::SITE_BASE, 'since' => $since, 'cooldown' => $cooldown],
        );

        $targets = [];
        foreach (array_merge($fresh, $rest) as $row) {
            $targets[(int) $row['id']] = (string) $row['slug'];
        }

        return array_slice($targets, 0, $quota, preserve_keys: true);
    }
}
