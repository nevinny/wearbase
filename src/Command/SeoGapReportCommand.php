<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\AdminNotifier;
use App\Service\Seo\SeoQueryGapProvider;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Автопилот position-gap листа (docs/yandex_ai_visibility_monitoring.md): раньше
 * это был ручной прогон двух SQL-запросов по пятницам. Читает yandex_query_stats
 * (последний date_to) + gsc_query_stats (сумма по окну), берёт «показы есть,
 * позиция>10» (2-я страница со спросом) и раскладывает по группам интента —
 * ТОТ ЖЕ AioQueryClassifier, что app:seo:aio-queries и app:seo:aio-remediate
 * (никакой второй классификации не изобретаем).
 *
 * Группы (порядок = приоритет матча):
 * - brand_entity        — classifier: «X чей бренд» → проверить app:seo:aio-remediate.
 * - replace_comparison  — classifier: comparison ИЛИ RU-маркеры замены (замен-, аналог-,
 *                          «X это Y») → листикл app:seo:replace-listicle.
 * - geo_category        — гео-паттерн (спб/петербург/москва) → нет посадочной.
 * - navigation          — не подошло выше, но запрос содержит title/slug опубликованного
 *                          бренда → аудит карточки (скилл brand-audit).
 * - other               — не классифицировано.
 *
 * Две полосы позиций (--band), они не пересекаются и вместе покрывают 4-ю позицию и ниже:
 * - gap      — position > 10: вторая страница, посадочной либо нет, либо она слабая;
 * - striking — position 4–10 (3 < pos ≤ 10): страница УЖЕ в топ-10, но не в топ-3.
 *              Дожать её дешевле, чем родить новую: сверить с топ-3 по главному запросу
 *              и добавить недостающее (раздел, таблица, FAQ) — правка существующего URL.
 *              Для GSC URL-владелец резолвится из gsc_page_stats (там есть query),
 *              у Яндекса page-level запросов нет — там только сам запрос.
 *
 * Только чтение по brand/*_query_stats/gsc_page_stats. Побочный эффект — ОДНА строка на
 * (source,band,intent_group) в seo_gap_snapshot (для трендов неделя-к-неделе),
 * пропускается с --stdout-only. --notify шлёт компактную сводку в Telegram
 * тем же AdminNotifier, что и остальные SEO-команды (app:seo:aio-remediate).
 *
 *   php bin/console app:seo:gap-report --stdout-only
 *   php bin/console app:seo:gap-report --band=striking --stdout-only
 *   php bin/console app:seo:gap-report --notify --no-debug   # крон, пн 08:00
 */
#[AsCommand(
    name: 'app:seo:gap-report',
    description: 'SEO: автопилот position-листа (gap >10 и дожим 4–10, спрос есть) — группировка по интенту + снапшот тренда',
)]
class SeoGapReportCommand extends Command
{
    /**
     * `action_prefix` дописывается перед интент-действием: в striking страница уже ранжируется,
     * поэтому дефолт — правка существующего URL, а не новая посадочная. Границы позиций (min/max)
     * теперь живут в SeoQueryGapProvider::BAND_BOUNDS — здесь только UI-метаданные.
     */
    private const BAND_META = [
        'striking' => [
            'label'         => 'Дожим (топ-10 без топ-3, позиция 4–10)',
            'icon'          => '🎯',
            'action_prefix' => 'страница уже в топ-10 — сверить с топ-3 по главному запросу и добавить недостающее (раздел, таблица, FAQ); ',
        ],
        'gap' => [
            'label'         => 'Gap (2-я страница, позиция >10)',
            'icon'          => '🕳',
            'action_prefix' => '',
        ],
    ];

    private const GROUP_META = [
        'brand_entity'       => ['label' => 'Бренд/сущность («чей бренд»)', 'action' => 'проверить app:seo:aio-remediate по карточке (FAQ «Что за бренд?»)'],
        'replace_comparison' => ['label' => 'Замена/сравнение', 'action' => 'листикл app:seo:replace-listicle'],
        'geo_category'       => ['label' => 'Гео-категория', 'action' => 'нет посадочной — нужна страница-агрегатор по городу'],
        'navigation'         => ['label' => 'Навигационный (бренд рядом в топе)', 'action' => 'аудит полноты карточки (скилл brand-audit)'],
        'other'              => ['label' => 'Прочее', 'action' => '—'],
    ];

    public function __construct(
        private readonly SeoQueryGapProvider $gapProvider,
        private readonly Connection $db,
        private readonly AdminNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'yandex|gsc|both', 'both')
            ->addOption('band', null, InputOption::VALUE_REQUIRED, 'striking (поз. 4–10) | gap (поз. >10) | both', 'both')
            ->addOption('min-shows', null, InputOption::VALUE_REQUIRED, 'Мин. показов, чтобы считать спрос', '10')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Топ-N строк на источник (по показам)', '40')
            ->addOption('stdout-only', null, InputOption::VALUE_NONE, 'Только вывод, без снапшота и без TG')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести полные данные в JSON вместо таблиц')
            ->addOption('notify', null, InputOption::VALUE_NONE, 'Отправить компактную сводку в Telegram (AdminNotifier)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $source     = (string) $input->getOption('source');
        $band       = (string) $input->getOption('band');
        $minShows   = max(1, (int) $input->getOption('min-shows'));
        $limit      = max(1, (int) $input->getOption('limit'));
        $stdoutOnly = (bool) $input->getOption('stdout-only');
        $json       = (bool) $input->getOption('json');
        $notify     = (bool) $input->getOption('notify');

        $bands = $this->gapProvider->resolveBands($band);
        if ($bands === []) {
            $io->error(sprintf('Неизвестная полоса --band=%s (ожидается striking|gap|both).', $band));
            return Command::INVALID;
        }
        // Guard против рассинхронизации констант: BAND_META (UI-метаданные, здесь) и
        // SeoQueryGapProvider::BAND_BOUNDS (границы позиций) — две отдельные константы
        // с 2026-09-23 (провайдер переиспользует app:seo:competitor-scan). Добавили полосу
        // в одну и забыли парную — таблица/TG-дайджест ниже упадут на null-офсете вместо
        // понятной ошибки. См. SeoGapReportBandMetaTest.
        foreach ($bands as $bandName) {
            if (!isset(self::BAND_META[$bandName])) {
                throw new \LogicException("SeoGapReportCommand::BAND_META не содержит полосу «{$bandName}» из SeoQueryGapProvider::BAND_BOUNDS — рассинхронизация констант.");
            }
        }

        $io->title('SEO · position-лист (дожим 4–10 + gap >10) — автопилот');

        $byBand = [];
        foreach ($bands as $bandName) {
            $rows = $this->gapProvider->fetchBandRows($bandName, $source, $minShows, $limit);
            if ($rows !== []) {
                $byBand[$bandName] = $this->buildGroups($rows);
            }
        }

        if ($byBand === []) {
            $io->warning('Строк нет (пусто в yandex_query_stats/gsc_query_stats или все позиции вне выбранных полос).');
            return Command::SUCCESS;
        }

        if ($json) {
            $output->writeln(json_encode(['as_of' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d'), 'bands' => $byBand], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            foreach ($byBand as $bandName => $groups) {
                $meta  = self::BAND_META[$bandName];
                $total = array_sum(array_map(static fn (array $g) => count($g['rows']), $groups));
                $io->title(sprintf('%s %s — строк: %d', $meta['icon'], $meta['label'], $total));

                foreach ($groups as $g) {
                    $io->section(sprintf('%s — %d', $g['label'], count($g['rows'])));
                    $io->table(
                        ['Запрос', 'Показы', 'Позиция', 'Источник', 'URL-владелец'],
                        array_map(
                            static fn (array $r) => [mb_substr($r['query'], 0, 50), $r['shows'], $r['position'], $r['source'], $r['page'] !== null ? mb_substr($r['page'], 0, 45) : '—'],
                            $g['rows'],
                        ),
                    );
                    $io->text('→ ' . $meta['action_prefix'] . $g['action']);
                    $io->newLine();
                }
            }
            $io->section('Компактная сводка (превью того, что уйдёт в TG с --notify)');
            $io->text(strip_tags($this->formatDigest($byBand)));
        }

        if (!$stdoutOnly) {
            foreach ($byBand as $bandName => $groups) {
                $this->persistSnapshot($bandName, $this->snapshotRows($groups));
            }

            if ($notify && $this->notifier->isEnabled()) {
                $this->notifier->send($this->formatDigest($byBand));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Группировка строк полосы по интенту. Возвращает только группы с непустым списком,
     * порядок — приоритет из GROUP_META. Сама классификация (SQL-резолв фраз, бренды,
     * regex интента) — в SeoQueryGapProvider (переиспользуется app:seo:competitor-scan).
     *
     * @param list<array{query:string,shows:int,position:float,source:string,page:?string}> $rows
     * @return array<string,array{label:string,action:string,rows:list<array{query:string,shows:int,position:float,source:string,page:?string}>}>
     */
    private function buildGroups(array $rows): array
    {
        $brandNames = $this->gapProvider->fetchPublishedBrandNames();

        $groups = [];
        foreach (self::GROUP_META as $name => $meta) {
            $groups[$name] = ['label' => $meta['label'], 'action' => $meta['action'], 'rows' => []];
        }

        foreach ($rows as $row) {
            $groups[$this->gapProvider->classifyGroup($row['query'], $brandNames)]['rows'][] = $row;
        }

        foreach ($groups as &$g) {
            usort($g['rows'], static fn (array $a, array $b) => $b['shows'] <=> $a['shows']);
        }

        return array_filter($groups, static fn (array $g) => $g['rows'] !== []);
    }

    /**
     * @param array<string,array{label:string,action:string,rows:list<array{query:string,shows:int,position:float,source:string,page:?string}>}> $groups
     * @return list<array{source:string,group:string,count:int,top_query:string}>
     */
    private function snapshotRows(array $groups): array
    {
        $bySourceGroup = [];
        foreach ($groups as $name => $g) {
            foreach ($g['rows'] as $row) {
                $key = $row['source'] . '|' . $name;
                if (!isset($bySourceGroup[$key])) {
                    $bySourceGroup[$key] = ['source' => $row['source'], 'group' => $name, 'count' => 0, 'top_query' => $row['query'], 'top_shows' => $row['shows']];
                }
                $bySourceGroup[$key]['count']++;
                if ($row['shows'] > $bySourceGroup[$key]['top_shows']) {
                    $bySourceGroup[$key]['top_query'] = $row['query'];
                    $bySourceGroup[$key]['top_shows'] = $row['shows'];
                }
            }
        }

        return array_values($bySourceGroup);
    }

    /** @param list<array{source:string,group:string,count:int,top_query:string}> $rows */
    private function persistSnapshot(string $band, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        foreach ($rows as $r) {
            $this->db->executeStatement(
                'INSERT INTO seo_gap_snapshot (captured_on, source, band, intent_group, gap_count, top_query)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE gap_count = VALUES(gap_count), top_query = VALUES(top_query)',
                [$today, $r['source'], $band, $r['group'], $r['count'], mb_substr($r['top_query'], 0, 255)],
            );
        }
    }

    /**
     * Компактная HTML-сводка под Telegram (parse_mode=HTML, см. TelegramNotifier) — топ-N
     * запросов на группу, обрезка по символам, чтобы не разваливать сообщение.
     * Полосы идут в порядке BAND_META: сначала дожим (дешевле), потом gap.
     *
     * @param array<string,array<string,array{label:string,action:string,rows:list<array{query:string,shows:int,position:float,source:string,page:?string}>}>> $byBand
     */
    private function formatDigest(array $byBand, int $topPerGroup = 3, int $charCap = 1800): string
    {
        $lines = [sprintf('<b>SEO position-лист · %s</b>', (new \DateTime('now', new \DateTimeZone('Europe/Moscow')))->format('d.m'))];

        foreach ($byBand as $bandName => $groups) {
            $meta  = self::BAND_META[$bandName];
            $total = array_sum(array_map(static fn (array $g) => count($g['rows']), $groups));
            $lines[] = sprintf("\n<b>%s %s — %d</b>", $meta['icon'], htmlspecialchars($meta['label']), $total);

            foreach ($groups as $g) {
                $lines[] = sprintf('<b>%s (%d):</b>', htmlspecialchars($g['label']), count($g['rows']));
                foreach (array_slice($g['rows'], 0, $topPerGroup) as $row) {
                    $lines[] = sprintf(
                        '• %s — %d показ., поз.%s [%s]%s',
                        htmlspecialchars($row['query']),
                        $row['shows'],
                        $row['position'],
                        $row['source'],
                        $row['page'] !== null ? "\n  ↳ " . htmlspecialchars($row['page']) : '',
                    );
                }
                $lines[] = '→ ' . htmlspecialchars($meta['action_prefix'] . $g['action']);
            }
        }

        $msg = implode("\n", $lines);
        if (mb_strlen($msg) > $charCap) {
            $msg = mb_substr($msg, 0, $charCap - 1) . '…';
        }

        return $msg;
    }
}
