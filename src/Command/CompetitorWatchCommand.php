<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\AdminNotifier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Суточный срез публичных метрик конкурента + дельта к прошлому срезу.
 *
 * Зачем именно так: у ProVybor открыт публичный REST (`/api/v1/companies`,
 * `/api/v1/catalog/products`, `/api/v1/news`), поэтому число поставщиков и товаров берётся
 * ТОЧНЫМ числом из их же `meta.total` — это лучше любых оценок трафика. Свежесть каталога
 * считаем по `lastmod` в их sitemap. Разбор конкурента — docs/competitor_provybor.md.
 *
 * Идемпотентно: один срез на (конкурент, дата), повторный запуск за тот же день обновляет строку.
 *
 *   php bin/console app:competitor:watch --no-debug             # посмотреть
 *   php bin/console app:competitor:watch --notify --no-debug    # крон, 09:25
 */
#[AsCommand(
    name: 'app:competitor:watch',
    description: 'Срез публичных метрик конкурентов (поставщики/товары/свежесть/релизы) + дельта',
)]
class CompetitorWatchCommand extends Command
{
    /** Что и откуда снимаем. Добавить конкурента = добавить строку сюда. */
    private const SOURCES = [
        'provybor' => [
            'title'     => 'ProVybor',
            'companies' => 'https://provybor.com/api/v1/companies',
            'products'  => 'https://provybor.com/api/v1/catalog/products',
            'news'      => 'https://provybor.com/api/v1/news',
            'sitemap'   => 'https://provybor.com/sitemap.xml',
        ],
    ];

    /** Честный UA: конкурент вправе видеть, кто ходит по его публичному API. */
    private const UA = 'Mozilla/5.0 (compatible; wearbase-research/1.0; +https://wearbase.ru)';

    public function __construct(
        private readonly Connection $db,
        private readonly HttpClientInterface $http,
        private readonly AdminNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('competitor', null, InputOption::VALUE_REQUIRED, 'Кого снимать (slug из SOURCES)', 'provybor')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Не писать срез в БД')
            ->addOption('notify', null, InputOption::VALUE_NONE, 'Отправить сводку в Telegram (AdminNotifier)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $slug = (string) $input->getOption('competitor');

        if (!isset(self::SOURCES[$slug])) {
            $io->error(sprintf('Неизвестный конкурент «%s». Доступны: %s', $slug, implode(', ', array_keys(self::SOURCES))));

            return Command::FAILURE;
        }

        $src = self::SOURCES[$slug];
        $io->title('Слежка за конкурентом: ' . $src['title']);

        $errors = [];
        $now    = new \DateTimeImmutable();

        $companies = $this->totalFromApi($src['companies'], $errors);
        $products  = $this->totalFromApi($src['products'], $errors);
        [$sitemapUrls, $fresh24h] = $this->sitemapStats($src['sitemap'], $now, $errors);
        [$newsOn, $newsTitle]     = $this->latestNews($src['news'], $errors);

        $prev = $this->db->fetchAssociative(
            'SELECT captured_on, companies_total, products_total, sitemap_urls FROM competitor_snapshot
             WHERE competitor = :c AND captured_on < :today ORDER BY captured_on DESC LIMIT 1',
            ['c' => $slug, 'today' => $now->format('Y-m-d')]
        ) ?: null;

        $rows = [
            ['Поставщиков (API)', $this->fmt($companies), $this->delta($companies, $prev['companies_total'] ?? null)],
            ['Товаров (API)',     $this->fmt($products),  $this->delta($products, $prev['products_total'] ?? null)],
            ['URL в sitemap',     $this->fmt($sitemapUrls), $this->delta($sitemapUrls, $prev['sitemap_urls'] ?? null)],
            ['Карточек тронуто за сутки', $this->fmt($fresh24h), '—'],
            ['Последний релиз',   $newsOn ? $newsOn . ' — ' . mb_substr((string) $newsTitle, 0, 60) : '—', '—'],
        ];
        $io->table(['Метрика', 'Сейчас', 'Δ к ' . ($prev['captured_on'] ?? 'первому срезу')], $rows);

        if ($errors !== []) {
            $io->warning('Часть метрик не снялась: ' . implode('; ', $errors));
        }

        if ($input->getOption('dry-run')) {
            $io->note('--dry-run: срез не сохранён');
        } else {
            $this->db->executeStatement(
                'INSERT INTO competitor_snapshot
                    (competitor, captured_on, captured_at, companies_total, products_total, sitemap_urls, fresh_24h, news_latest_on, news_latest_title, errors)
                 VALUES (:c, :on, :at, :companies, :products, :urls, :fresh, :news_on, :news_title, :errors)
                 ON DUPLICATE KEY UPDATE
                    captured_at = VALUES(captured_at), companies_total = VALUES(companies_total),
                    products_total = VALUES(products_total), sitemap_urls = VALUES(sitemap_urls),
                    fresh_24h = VALUES(fresh_24h), news_latest_on = VALUES(news_latest_on),
                    news_latest_title = VALUES(news_latest_title), errors = VALUES(errors)',
                [
                    'c'          => $slug,
                    'on'         => $now->format('Y-m-d'),
                    'at'         => $now->format('Y-m-d H:i:s'),
                    'companies'  => $companies,
                    'products'   => $products,
                    'urls'       => $sitemapUrls,
                    'fresh'      => $fresh24h,
                    'news_on'    => $newsOn,
                    'news_title' => $newsTitle !== null ? mb_substr($newsTitle, 0, 255) : null,
                    'errors'     => $errors !== [] ? mb_substr(implode('; ', $errors), 0, 500) : null,
                ]
            );
            $io->success('Срез сохранён');
        }

        if ($input->getOption('notify') && $this->notifier->isEnabled()) {
            $this->notifier->send($this->digest($src['title'], $companies, $products, $fresh24h, $newsOn, $newsTitle, $prev));
            $io->text('Сводка отправлена в Telegram');
        }

        return Command::SUCCESS;
    }

    /** `meta.total` из пагинированного Laravel-ресурса. */
    private function totalFromApi(string $url, array &$errors): ?int
    {
        try {
            $data = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => self::UA],
                'timeout' => 20,
            ])->toArray(false);

            $total = $data['meta']['total'] ?? null;

            return is_numeric($total) ? (int) $total : null;
        } catch (\Throwable $e) {
            $errors[] = basename(parse_url($url, PHP_URL_PATH) ?: $url) . ': ' . $e->getMessage();

            return null;
        }
    }

    /** @return array{0: ?int, 1: ?int} [всего URL, тронуто за последние 24ч] */
    private function sitemapStats(string $url, \DateTimeImmutable $now, array &$errors): array
    {
        try {
            $xml = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => self::UA],
                'timeout' => 30,
            ])->getContent(false);

            $total = preg_match_all('~<loc>~', $xml);

            $fresh  = 0;
            $cutoff = $now->modify('-24 hours');
            if (preg_match_all('~<lastmod>([^<]+)</lastmod>~', $xml, $m)) {
                foreach ($m[1] as $stamp) {
                    try {
                        if (new \DateTimeImmutable($stamp) >= $cutoff) {
                            $fresh++;
                        }
                    } catch (\Throwable) {
                        // битый lastmod — не повод падать
                    }
                }
            }

            return [$total, $fresh];
        } catch (\Throwable $e) {
            $errors[] = 'sitemap: ' . $e->getMessage();

            return [null, null];
        }
    }

    /** @return array{0: ?string, 1: ?string} [дата, заголовок] последней публикации */
    private function latestNews(string $url, array &$errors): array
    {
        try {
            $data  = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => self::UA],
                'timeout' => 20,
            ])->toArray(false);
            $first = $data['data'][0] ?? null;

            if (!is_array($first)) {
                return [null, null];
            }

            $stamp = $first['published_at'] ?? $first['created_at'] ?? null;

            return [
                is_string($stamp) ? substr($stamp, 0, 10) : null,
                isset($first['title']) ? (string) $first['title'] : null,
            ];
        } catch (\Throwable $e) {
            $errors[] = 'news: ' . $e->getMessage();

            return [null, null];
        }
    }

    private function fmt(?int $v): string
    {
        return $v === null ? '—' : number_format($v, 0, '.', ' ');
    }

    private function delta(?int $now, ?int $was): string
    {
        if ($now === null || $was === null) {
            return '—';
        }

        $d = $now - $was;

        return $d === 0 ? '0' : sprintf('%+d', $d);
    }

    private function digest(
        string $title,
        ?int $companies,
        ?int $products,
        ?int $fresh,
        ?string $newsOn,
        ?string $newsTitle,
        ?array $prev,
    ): string {
        $lines = [sprintf('<b>%s</b> — суточный срез', $title)];

        $lines[] = sprintf('Поставщиков: <b>%s</b> (%s)', $this->fmt($companies), $this->delta($companies, $prev['companies_total'] ?? null));
        $lines[] = sprintf('Товаров: <b>%s</b> (%s)', $this->fmt($products), $this->delta($products, $prev['products_total'] ?? null));
        $lines[] = sprintf('Тронуто карточек за сутки: %s', $this->fmt($fresh));

        if ($newsOn !== null) {
            $lines[] = sprintf('Последний релиз: %s — %s', $newsOn, htmlspecialchars(mb_substr((string) $newsTitle, 0, 80)));
        }

        return implode("\n", $lines);
    }
}
