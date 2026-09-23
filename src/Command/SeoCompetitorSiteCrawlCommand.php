<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CompetitorArticle;
use App\Repository\CompetitorArticleRepository;
use App\Service\Seo\CompetitorPageClassifier;
use App\Service\WebScraperService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Разведка блогов у известных конкурентов-приложений для гардероба (не через SERP —
 * платный Yandex Search API осознанно выключен, см. docs/seo_competitor_content.md).
 * Вместо этого — прямой обход уже известных доменов через
 * WebScraperService::discoverSitePages() (sitemap.xml + fallback на ссылки с главной).
 *
 * Разовая задача (координатор попросил обогатить локальный корпус конкурентов для
 * gap-анализа блога про гардероб), не универсальный краулер — список доменов зашит.
 *
 * Флоу на домен: discoverSitePages() → CompetitorPageClassifier::classify($url, [])
 * (пустой $officialHosts — тут нет каталожных брендов, это чужие приложения) → только
 * TYPE_ARTICLE идёт в скрейп → CompetitorArticle 30-дневный кэш (fetchOrReuseArticle,
 * идентично паттерну SeoCompetitorScanCommand) → WebScraperService::fetchCleanTextWithStatus().
 *
 * discoverSitePages вызывается с большим hardCap (sitemap.xml — один дешёвый HTTP-запрос,
 * можно смотреть много URL без реального скрейпа), а РЕАЛЬНЫЙ скрейп ограничен
 * self::SCRAPE_CAP на домен — это разведка блога, а не полноценный краул сайта.
 * Среди article-кандидатов сначала скрейпятся похожие на блог/статью пути
 * (BLOG_PATH_HINTS) — иначе капа на скрейп не хватит на реальные статьи, если сайт
 * отдаёт в sitemap много служебных страниц (pricing/privacy/login).
 *
 *   php bin/console app:seo:competitor-site-crawl --dry-run
 *   php bin/console app:seo:competitor-site-crawl
 *   php bin/console app:seo:competitor-site-crawl --force
 */
#[AsCommand(
    name: 'app:seo:competitor-site-crawl',
    description: 'Обход известных доменов конкурентов (fits-app, getwardrobe, outfitly, n2b) → CompetitorArticle',
)]
class SeoCompetitorSiteCrawlCommand extends Command
{
    private const DISCOVER_CAP = 400; // sitemap.xml — дёшево, можно смотреть много URL
    private const SCRAPE_CAP = 40;    // реальный HTTP-скрейп на домен — разведка, не краул
    private const FRESH_DAYS = 30;
    private const MIN_WORDS_SUBSTANTIVE = 150; // ниже — считаем "пусто/не статья" в отчёте

    /** @var array<int,array{slug:string,siteUrl:string}> */
    private const DOMAINS = [
        ['slug' => 'fits-app', 'siteUrl' => 'https://www.fits-app.com'],
        ['slug' => 'getwardrobe', 'siteUrl' => 'https://getwardrobe.com'],
        ['slug' => 'outfitly', 'siteUrl' => 'https://outfitlyapp.com/ru/'],
        ['slug' => 'n2b-style', 'siteUrl' => 'https://n2b-style.com'],
    ];

    /** Приоритет при отборе в SCRAPE_CAP, если article-кандидатов больше капа. */
    private const BLOG_PATH_HINTS = ['blog', 'journal', 'article', 'guide', 'post', 'knowledge', 'wiki', 'tips', 'how-to'];

    public function __construct(
        private readonly WebScraperService $scraper,
        private readonly CompetitorPageClassifier $classifier,
        private readonly EntityManagerInterface $em,
        private readonly CompetitorArticleRepository $articleRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Перескрейпить, даже если CompetitorArticle свежий (<30д)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только discover+classify, без скрейпа/записи в БД')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $now = new \DateTimeImmutable();

        $rows = [];
        foreach (self::DOMAINS as $domain) {
            $io->section($domain['slug'] . ' — ' . $domain['siteUrl']);

            $discovered = $this->scraper->discoverSitePages($domain['siteUrl'], self::DISCOVER_CAP);
            $discoveredCount = count($discovered);

            $articleUrls = [];
            foreach ($discovered as $url) {
                if ($this->classifier->classify($url, []) === CompetitorPageClassifier::TYPE_ARTICLE) {
                    $articleUrls[] = $url;
                }
            }
            $articleCount = count($articleUrls);

            // Многоязычные сайты (fits-app: /ru/ /de/ /es/ /pt/…) отдают одну и ту же
            // статью под N локалями — считаем это ОДНОЙ статьёй в отчёте (иначе цифра
            // "article" вводит в заблуждение), но продолжаем скрейпить все локали (ru
            // приоритетнее — полезнее для anti-dup и генерации на русском).
            $uniqueUrls = $this->collapseLocales($articleUrls);
            $uniqueCount = count($uniqueUrls);

            $toScrape = $this->prioritizeForScrape($articleUrls, self::SCRAPE_CAP);
            $io->text(sprintf(
                '  discovered=%d, classified article=%d (уникальных без учёта локали=%d), отобрано на скрейп=%d (cap=%d)',
                $discoveredCount,
                $articleCount,
                $uniqueCount,
                count($toScrape),
                self::SCRAPE_CAP,
            ));

            $nonEmpty = 0;
            $substantive = 0;

            if (!$dryRun) {
                foreach ($toScrape as $url) {
                    $article = $this->fetchOrReuseArticle($url, $domain['slug'], $now, $force);
                    if ($article === null) {
                        continue;
                    }
                    $content = $article->getContent();
                    if ($content !== null && trim($content) !== '') {
                        $nonEmpty++;
                        if (($article->getWordCount() ?? 0) >= self::MIN_WORDS_SUBSTANTIVE) {
                            $substantive++;
                        }
                    }
                }
                $this->em->clear();
            }

            $rows[] = [
                $domain['slug'],
                $discoveredCount,
                $articleCount,
                $uniqueCount,
                count($toScrape),
                $dryRun ? '—' : $nonEmpty,
                $dryRun ? '—' : $substantive,
            ];
        }

        $io->newLine();
        $io->table(
            ['Домен', 'Discovered', 'Article', 'Уникальных (без локали)', 'Отобрано (cap)', 'Непусто', '≥' . self::MIN_WORDS_SUBSTANTIVE . ' слов'],
            $rows,
        );

        return Command::SUCCESS;
    }

    /**
     * Article-кандидаты сортируются: сначала похожие на блог/статью пути
     * (BLOG_PATH_HINTS в URL), внутри группы — сначала /ru/ или без локали (полезнее
     * для anti-dup на русском и для генерации), остальные локали — ниже. Стабильная
     * сортировка (PHP 8+ usort), порядок связей = исходный порядок discovery.
     * Возвращает первые $cap URL.
     *
     * @param string[] $urls
     * @return string[]
     */
    private function prioritizeForScrape(array $urls, int $cap): array
    {
        $scored = [];
        foreach ($urls as $url) {
            $path = strtolower((string) parse_url($url, PHP_URL_PATH));
            $isHinted = false;
            foreach (self::BLOG_PATH_HINTS as $hint) {
                if (str_contains($path, $hint)) {
                    $isHinted = true;
                    break;
                }
            }
            $score = ($isHinted ? 2 : 0) + ($this->localePrefix($path) === null || $this->localePrefix($path) === 'ru' ? 1 : 0);
            $scored[] = ['url' => $url, 'score' => $score];
        }

        usort($scored, static fn(array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice(array_column($scored, 'url'), 0, $cap);
    }

    /**
     * Без учёта локали (`/ru/posts/x` и `/de/posts/x` — одна и та же статья) — сколько
     * РЕАЛЬНО разных статей нашлось, отдельно от общего числа URL с локале-дублями.
     *
     * @param string[] $urls
     * @return string[] уникальные канонические пути (для подсчёта, не для скрейпа)
     */
    private function collapseLocales(array $urls): array
    {
        $canonical = [];
        foreach ($urls as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $path = strtolower((string) parse_url($url, PHP_URL_PATH));
            $locale = $this->localePrefix($path);
            $withoutLocale = $locale !== null ? preg_replace('#^/' . $locale . '(/|$)#', '/', $path) : $path;
            $canonical[$host . rtrim((string) $withoutLocale, '/')] = true;
        }

        return array_keys($canonical);
    }

    /** Известные локали проекта (см. CLAUDE.md: en|ru|zh|ar|tr|de|fr|es|ko) + pt (замечен у getwardrobe). */
    private const KNOWN_LOCALES = ['en', 'ru', 'zh', 'ar', 'tr', 'de', 'fr', 'es', 'ko', 'pt'];

    private function localePrefix(string $path): ?string
    {
        if (preg_match('#^/([a-z]{2})(/|$)#', $path, $m) && in_array($m[1], self::KNOWN_LOCALES, true)) {
            return $m[1];
        }

        return null;
    }

    /**
     * 30-дневный кэш по URL (CLAUDE.md; тот же паттерн, что
     * SeoCompetitorScanCommand::fetchOrReuseArticle). Flush per-article — здесь нет
     * платного API, который надо беречь батчем, зато проще держать EM в порядке.
     */
    private function fetchOrReuseArticle(string $url, string $domainSlug, \DateTimeImmutable $now, bool $force): ?CompetitorArticle
    {
        $existing = $this->articleRepo->findByUrl($url);
        if ($existing !== null && !$force && $existing->isFresh($now, self::FRESH_DAYS)) {
            return $existing;
        }

        // keepTables: true — trafilatura markdown сохраняет заголовки «##» (структурный
        // сигнал для LlmService::extractCompetitorTopics), обычный режим их выкидывает.
        $fetched = $this->scraper->fetchCleanTextWithStatus($url, keepTables: true);
        $text = $fetched['text'];

        $article = $existing ?? new CompetitorArticle();
        if ($existing === null) {
            $article->setUrl($url)->setDomain(strtolower((string) parse_url($url, PHP_URL_HOST)));
        }
        $article->setPageType(CompetitorPageClassifier::TYPE_ARTICLE)
            ->setTitle($this->guessTitle($text, $url))
            ->setContent($text)
            ->setWordCount($text !== null ? (int) preg_match_all('/\p{L}+/u', $text) : null)
            ->setHttpStatus($fetched['httpStatus'])
            ->setFetchedAt($now);
        $this->em->persist($article);

        try {
            $this->em->flush();
        } catch (\Throwable) {
            // Гонка на unique-индекс url (маловероятно вне параллельного прогона) —
            // не роняем весь прогон домена, пропускаем этот URL.
            $this->em->clear();

            return null;
        }

        return $article;
    }

    /**
     * fetchCleanTextWithStatus не возвращает title (в отличие от SERP-варианта scan-команды,
     * где title берётся из выдачи) — без него отбор тем в шаге 2 слеп. Берём первую
     * непустую строку текста (обычно это H1/лид первого абзаца после trafilatura), иначе
     * — последний сегмент пути URL.
     */
    private function guessTitle(?string $text, string $url): ?string
    {
        if ($text !== null) {
            foreach (explode("\n", $text) as $line) {
                $line = trim($line, " \t#-");
                if ($line !== '') {
                    return mb_substr($line, 0, 255);
                }
            }
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $slug = $path !== '' ? basename($path) : $url;

        return mb_substr($slug, 0, 255);
    }
}
