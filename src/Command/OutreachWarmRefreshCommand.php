<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\AdminNotifier;
use App\Service\Outreach\WarmOfferService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * SALES-LOOP — еженедельный warm-refresh тёплых лидов (docs/sales_offer.md, оффер
 * «Размещение под ключ» 5000₽).
 *
 * Тёплый лид = опубликованный (active) бренд, чью карточку /ru/brands/<slug> реально
 * находят в поиске (клики/показы за {@see self::WINDOW_DAYS} дней, gsc_page_stats),
 * с email, которому ещё не готовили этот драфт (outreach_log).
 *
 * gsc_page_stats дедуп: строки уникальны по (page_url, day) начиная с
 * Version20260719_gsc_page_stats_dedup, но один и тот же логический адрес бренда
 * может встречаться под разными вариантами page_url (query-параметры, регистр) —
 * поэтому в SQL сначала схлопываем в MAX по (brand_id, day), и только потом
 * суммируем по дням (аддитивно, разные дни — это разный трафик, не дубли).
 *
 * ШАБЛОН, не LLM (дешевле/надёжнее): цифры трафика + эффект владения («карточка уже
 * собрана») + 2-3 похожих бренда (стиль/город) + оффер 5000₽ + подпись куратора —
 * приёмы из docs/klyucharev_decisions_2026.md «Второй проход».
 *
 * ЧЕЛОВЕК-ГЕЙТ: эта команда НИКОГДА не отправляет письма брендам — только пишет
 * драфты в var/outreach/warm-YYYY-MM-DD.md и шлёт сводку владельцу в TG. Реальная
 * отправка — отдельное ручное решение человека (вне этой команды).
 *
 *   php bin/console app:outreach:warm-refresh --dry-run   # посчитать, ничего не писать
 *   php bin/console app:outreach:warm-refresh              # реальный прогон (крон: пн 08:30)
 */
#[AsCommand(
    name: 'app:outreach:warm-refresh',
    description: 'SALES-LOOP: тёплые лиды по кликам/показам из поиска → драфты писем-офферов + сводка в TG (человек-гейт)',
)]
class OutreachWarmRefreshCommand extends Command
{
    private const WINDOW_DAYS    = WarmOfferService::WINDOW_DAYS;
    private const DEFAULT_LIMIT  = 20;
    private const DEFAULT_MIN_CLICKS = 1;
    private const LOG_TYPE       = 'warm_offer_draft';

    public function __construct(
        private readonly Connection $db,
        private readonly AdminNotifier $notifier,
        private readonly WarmOfferService $warmOffer,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Максимум тёплых лидов за прогон', (string) self::DEFAULT_LIMIT)
            ->addOption('min-clicks', null, InputOption::VALUE_REQUIRED, 'Минимум кликов за 28 дней, чтобы считать лид тёплым', (string) self::DEFAULT_MIN_CLICKS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Посчитать и показать кандидатов, ничего не писать (ни в outreach_log, ни в файл, ни в TG)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $limit     = max(1, (int) $input->getOption('limit'));
        $minClicks = max(0, (int) $input->getOption('min-clicks'));
        $dryRun    = (bool) $input->getOption('dry-run');

        $io->title('SALES-LOOP · warm-refresh тёплых лидов');

        $leads = $this->fetchWarmLeads($minClicks, $limit);
        if ($leads === []) {
            $io->success('Новых тёплых лидов нет (либо нет свежих кликов, либо все уже задрафтованы).');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Тёплых лидов: %d', count($leads)));
        $drafts = [];
        foreach ($leads as $lead) {
            $similar = $this->warmOffer->findSimilarBrands((int) $lead['id'], (string) ($lead['city'] ?? ''));
            $draft   = $this->warmOffer->buildDraft($lead, $similar);
            $drafts[] = $draft;

            $io->text(sprintf(
                '  · %s — клики %d / показы %d /ru/brands/%s',
                $lead['title'], $lead['clicks'], $lead['impressions'], $lead['slug'],
            ));

            if (!$dryRun) {
                $this->db->executeStatement(
                    'INSERT INTO outreach_log (brand_id, type, status, created_at) VALUES (:brand_id, :type, :status, :created_at)',
                    [
                        'brand_id'   => $lead['id'],
                        'type'       => self::LOG_TYPE,
                        'status'     => 'drafted',
                        'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ],
                );
            }
        }

        if ($dryRun) {
            $io->success(sprintf('Dry-run: %d кандидатов, файл/TG/outreach_log не тронуты.', count($drafts)));
            return Command::SUCCESS;
        }

        $file = $this->writeDraftsFile($drafts);
        $io->text('Драфты сохранены: ' . $file);

        $this->notifyOwner($drafts, $file);

        $io->success(sprintf('Готово: %d новых тёплых лидов, драфты в %s.', count($drafts), $file));

        return Command::SUCCESS;
    }

    /**
     * @return list<array{id:int,title:string,slug:string,email:string,city:?string,clicks:int,impressions:int}>
     */
    private function fetchWarmLeads(int $minClicks, int $limit): array
    {
        $since = (new \DateTimeImmutable())->modify('-' . self::WINDOW_DAYS . ' days')->format('Y-m-d');

        // Дедуп: сначала MAX по (brand_id, day) — гасит дубли одного и того же логического
        // адреса под разными вариантами page_url (query-параметры/регистр); дни между собой
        // суммируем аддитивно (реально разный трафик, не дубли).
        //
        // ⚠️ min_clicks ОБЯЗАТЕЛЬНО с явным типом ParameterType::INTEGER: PDO по умолчанию
        // биндит скалярные параметры как строки, а SQLite (наш тест-драйвер) сравнивает
        // INTEGER и TEXT по storage-class (INTEGER < TEXT всегда), из-за чего
        // "agg.clicks >= :min_clicks" молча всегда ложно на SQLite без явного типа
        // (MySQL так не делает — баг был бы незаметен в проде и жил бы только в тестах).
        $rows = $this->db->fetchAllAssociative(
            <<<'SQL'
                SELECT b.id, b.title, b.slug, b.email, b.city, agg.clicks, agg.impressions
                FROM brand b
                JOIN (
                    SELECT brand_id, SUM(clicks) AS clicks, SUM(impressions) AS impressions
                    FROM (
                        SELECT brand_id, day, MAX(clicks) AS clicks, MAX(impressions) AS impressions
                        FROM gsc_page_stats
                        WHERE brand_id IS NOT NULL AND query IS NULL AND day >= :since
                        GROUP BY brand_id, day
                    ) per_day
                    GROUP BY brand_id
                ) agg ON agg.brand_id = b.id
                WHERE b.status = 'active'
                  AND b.email IS NOT NULL AND b.email != ''
                  AND agg.clicks >= :min_clicks
                  AND NOT EXISTS (
                      SELECT 1 FROM outreach_log o WHERE o.brand_id = b.id AND o.type = :log_type
                  )
                ORDER BY agg.clicks DESC, agg.impressions DESC
                LIMIT
            SQL . ' ' . $limit,
            ['since' => $since, 'min_clicks' => $minClicks, 'log_type' => self::LOG_TYPE],
            ['since' => ParameterType::STRING, 'min_clicks' => ParameterType::INTEGER, 'log_type' => ParameterType::STRING],
        );

        return array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'title'       => (string) $r['title'],
            'slug'        => (string) $r['slug'],
            'email'       => (string) $r['email'],
            'city'        => $r['city'] !== null ? (string) $r['city'] : null,
            'clicks'      => (int) $r['clicks'],
            'impressions' => (int) $r['impressions'],
        ], $rows);
    }

    /**
     * @param list<array{lead: array, similar: array, subject: string, body: string}> $drafts
     */
    private function writeDraftsFile(array $drafts): string
    {
        $dir = $this->projectDir . '/var/outreach';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать ' . $dir);
        }

        $date = (new \DateTimeImmutable())->format('Y-m-d');
        $path = $dir . '/warm-' . $date . '.md';

        $lines = [sprintf('# Warm-refresh · %s · %d лидов', $date, count($drafts)), ''];
        foreach ($drafts as $d) {
            $lead = $d['lead'];
            $lines[] = sprintf('## %s (/ru/brands/%s)', $lead['title'], $lead['slug']);
            $lines[] = sprintf('- email: %s', $lead['email']);
            $lines[] = sprintf('- клики/показы за 28д: %d / %d', $lead['clicks'], $lead['impressions']);
            $lines[] = sprintf('- Тема: %s', $d['subject']);
            $lines[] = '';
            $lines[] = $d['body'];
            $lines[] = '';
            $lines[] = '---';
            $lines[] = '';
        }

        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    /**
     * @param list<array{lead: array, similar: array, subject: string, body: string}> $drafts
     */
    private function notifyOwner(array $drafts, string $file): void
    {
        if (!$this->notifier->isEnabled()) {
            return; // TG не настроен — деградируем в no-op (env пуст в test/офлайн)
        }

        $top = array_slice($drafts, 0, 5);
        $topLines = implode("\n", array_map(
            static fn (array $d) => sprintf(
                '• %s — клики %d / показы %d',
                htmlspecialchars((string) $d['lead']['title']), $d['lead']['clicks'], $d['lead']['impressions'],
            ),
            $top,
        ));

        $msg = sprintf(
            "<b>💌 Sales-loop: %d новых тёплых лидов</b>\n\n<b>Топ-5:</b>\n%s\n\n" .
            "Драфты: %s\n\nОтправка — вручную, после ручной вычитки.",
            count($drafts), $topLines, $file,
        );

        $this->notifier->send($msg);
    }
}
