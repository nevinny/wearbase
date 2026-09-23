<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandStyle;
use App\Entity\CompetitorArticle;
use App\Entity\SeoCompetitorScan;
use App\Notification\AdminNotifier;
use App\Repository\CompetitorArticleRepository;
use App\Repository\SeoCompetitorScanRepository;
use App\Service\LlmService;
use App\Service\Seo\CompetitorPageClassifier;
use App\Service\Seo\SeoQueryGapProvider;
use App\Service\WebScraperService;
use App\Service\YandexSearchClient;
use App\Service\YandexSearchMeter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Разведка конкурентов в выдаче → gap-контекст для существующих SEO-генераторов
 * (docs/seo_competitor_content.md, Skyscraper Technique). НЕ генератор контента:
 * по популярной SEO-фразе смотрит живую выдачу (YandexSearchClient, платный API),
 * скрейпит только article-результаты (CompetitorPageClassifier), извлекает у них
 * ТОЛЬКО темы/структуру (LlmService::extractCompetitorTopics — юридический риск
 * пересказа фактов конкурента запрещён промптом), и предлагает, какой существующей
 * командой (app:seo:listicle / app:seo:replace-listicle / app:seo:guide) закрыть
 * фразу через новый флаг --gap-context=<id>.
 *
 * Источник фраз — SeoQueryGapProvider (та же SQL-логика, что app:seo:gap-report):
 * показы+позиция из yandex_query_stats/gsc_query_stats (--source=yandex|gsc|both,
 * both = яндекс+GSC, ОБЕ полосы позиций striking+gap) либо brand_keyword
 * (--source=wordstat — опционально, Wordstat-ключ невалиден с 2026-08-20, данные
 * протухшие, см. docs/wordstat-api-key-invalid). Гейт интента исключает
 * brand_entity/navigation безусловно (спрос там навигационный — Skyscraper не
 * применим), --intent фильтрует из geo_category|replace_comparison|other.
 *
 * Идемпотентность: фраза, полностью проверенная (status=analyzed) за последние
 * 30 дней, повторно не сканируется (не расходуем платный API повторно на один и
 * тот же топ-N). Фраза, зависшая на status=scanned (LLM упал/прервали) — доснимается
 * из уже сохранённого SERP БЕЗ повторного платного запроса.
 *
 * Команда «по запросу» — платный Yandex Search API, в крон НЕ добавлена
 * (см. docs/commands.md, как app:seo:listicle).
 *
 *   php bin/console app:seo:competitor-scan --dry-run
 *   php bin/console app:seo:competitor-scan --limit=10 --serp-limit=5
 *   php bin/console app:seo:competitor-scan --source=gsc --intent=geo_category --notify
 */
#[AsCommand(
    name: 'app:seo:competitor-scan',
    description: 'SEO: разведка конкурентов в выдаче по популярным фразам → gap-контекст (--gap-context) для listicle/replace-listicle/guide',
)]
class SeoCompetitorScanCommand extends Command
{
    private const MIN_SHOWS = 10;
    private const FRESH_DAYS = 30;
    private const ANCHORS_FILE = '/config/seo/replacement_anchors.yaml';

    /** brand_entity/navigation исключены безусловно — не проходной параметр --intent. */
    private const ALLOWED_INTENTS = [
        SeoCompetitorScan::INTENT_GEO_CATEGORY,
        SeoCompetitorScan::INTENT_REPLACE_COMPARISON,
        SeoCompetitorScan::INTENT_OTHER,
    ];

    /** Стартовый гео-список (см. SeoQueryGapProvider::GEO_PATTERN) — для подстановки --city в рекомендацию. */
    private const CITY_CANONICAL = [
        'санкт-петербург' => 'Санкт-Петербург',
        'петербург'       => 'Санкт-Петербург',
        'спб'             => 'Санкт-Петербург',
        'москва'          => 'Москва',
        'москв'           => 'Москва',
    ];

    public function __construct(
        private readonly SeoQueryGapProvider $gapProvider,
        private readonly YandexSearchClient $yandexSearch,
        private readonly YandexSearchMeter $searchMeter,
        private readonly WebScraperService $scraper,
        private readonly CompetitorPageClassifier $classifier,
        private readonly LlmService $llm,
        private readonly EntityManagerInterface $em,
        private readonly CompetitorArticleRepository $articleRepo,
        private readonly SeoCompetitorScanRepository $scanRepo,
        private readonly Connection $db,
        private readonly AdminNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'wordstat|gsc|yandex|both (both=яндекс+GSC)', 'both')
            ->addOption('intent', null, InputOption::VALUE_REQUIRED, 'Через запятую: ' . implode(',', self::ALLOWED_INTENTS), implode(',', self::ALLOWED_INTENTS))
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Сколько фраз проверить за прогон', '10')
            ->addOption('serp-limit', null, InputOption::VALUE_REQUIRED, 'Топ-N URL выдачи на фразу', '5')
            ->addOption('notify', null, InputOption::VALUE_NONE, 'Отправить компактную сводку в Telegram (AdminNotifier)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только план (фразы+интент+рекомендация), без Yandex Search/скрейпа/LLM/записи')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $source    = (string) $input->getOption('source');
        $intents   = array_filter(array_map('trim', explode(',', (string) $input->getOption('intent'))));
        $limit     = max(1, (int) $input->getOption('limit'));
        $serpLimit = max(1, min(20, (int) $input->getOption('serp-limit')));
        $notify    = (bool) $input->getOption('notify');
        $dryRun    = (bool) $input->getOption('dry-run');

        if (!in_array($source, ['wordstat', 'gsc', 'yandex', 'both'], true)) {
            $io->error("Неизвестный --source={$source} (ожидается wordstat|gsc|yandex|both).");
            return Command::INVALID;
        }
        $badIntents = array_diff($intents, self::ALLOWED_INTENTS);
        if ($intents === [] || $badIntents !== []) {
            $io->error(sprintf('Неизвестный --intent (%s). Доступно: %s.', implode(',', $badIntents), implode(',', self::ALLOWED_INTENTS)));
            return Command::INVALID;
        }

        if (!$dryRun && !$this->yandexSearch->isConfigured()) {
            $io->error('YandexSearchClient не сконфигурирован (YANDEX_SEARCH_API_KEY/YANDEX_SEARCH_FOLDER_ID) — платный SERP недоступен. Запустите --dry-run для предпросмотра плана.');
            return Command::FAILURE;
        }

        $io->title('SEO · разведка конкурентов в выдаче' . ($dryRun ? ' — DRY-RUN' : ''));

        $now        = new \DateTimeImmutable();
        $freshCutoff = $now->modify('-' . self::FRESH_DAYS . ' days');
        $brandNames = $this->gapProvider->fetchPublishedBrandNames();
        $officialHosts = $this->officialHosts();
        $anchorNeedles = $this->loadAnchorNeedles();
        $styleNeedles  = $this->loadStyleNeedles();

        $poolLimit  = max(40, $limit * 4);
        $candidates = $this->resolveCandidates($source, $poolLimit);

        $picked = [];
        foreach ($candidates as $c) {
            if (count($picked) >= $limit) {
                break;
            }
            $intent = $this->gapProvider->classifyGroup($c['query'], $brandNames);
            if (!in_array($intent, self::ALLOWED_INTENTS, true) || !in_array($intent, $intents, true)) {
                continue; // brand_entity/navigation исключены безусловно + фильтр --intent
            }
            $recent = $this->scanRepo->findLatestByKeyword($c['query']);
            $recentFresh = $recent !== null && $recent->getCheckedAt() !== null && $recent->getCheckedAt() > $freshCutoff;
            if ($recentFresh && $recent->getStatus() === SeoCompetitorScan::STATUS_ANALYZED) {
                continue; // уже полностью проверена недавно — не расходуем лимит и API
            }
            $picked[] = [
                'row'      => $c,
                'intent'   => $intent,
                'resumeId' => ($recentFresh && $recent->getStatus() === SeoCompetitorScan::STATUS_SCANNED) ? $recent->getId() : null,
            ];
        }

        if ($picked === []) {
            $io->warning('Кандидатных фраз нет (пусто в источнике данных, все свежие/навигационные, либо все уже проанализированы за 30д).');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $rows = [];
            foreach ($picked as $p) {
                $route = $this->recommendRoute($p['intent'], $p['row']['query'], '<ID>', $anchorNeedles, $styleNeedles);
                $rows[] = [mb_substr($p['row']['query'], 0, 45), $p['row']['source'], $p['intent'], $p['row']['shows'], $route];
            }
            $io->table(['Фраза', 'Источник', 'Интент', 'Показы', 'Рекомендация'], $rows);
            $io->note('DRY-RUN: Yandex Search/скрейп/LLM/запись не выполнялись.');

            return Command::SUCCESS;
        }

        $report = [];
        foreach ($picked as $p) {
            if (!$this->searchMeter->allowed()) {
                $io->warning(sprintf('Дневной потолок Yandex Search API исчерпан (%d/%d) — прерываю прогон.', $this->searchMeter->todayCount(), $this->searchMeter->dailyCap()));
                break;
            }

            $query = $p['row']['query'];
            $io->section($query);

            $scan = $p['resumeId'] !== null ? $this->em->find(SeoCompetitorScan::class, $p['resumeId']) : null;

            if ($scan !== null) {
                $io->text('  досниманиe из ранее сохранённого SERP (без повторного платного запроса)');
                $articleTexts = $this->articleTextsFromSerpResults($scan->getSerpResults());
            } else {
                $serp = $this->yandexSearch->search($query, $serpLimit);
                if ($serp === []) {
                    $io->text('  пустая выдача (нет результатов / ошибка API) — пропуск');
                    continue;
                }

                [$serpResults, $articleTexts] = $this->buildSerpResults($serp, $officialHosts, $now);

                $scan = new SeoCompetitorScan();
                $scan->setKeyword($query)
                    ->setDemandSource($p['row']['source'])
                    ->setIntentGroup($p['intent'])
                    ->setOurUrl($p['row']['page'])
                    ->setSerpResults($serpResults)
                    ->setStatus(SeoCompetitorScan::STATUS_SCANNED)
                    ->setCheckedAt($now);
                $this->em->persist($scan);
                $this->em->flush(); // чекпоинт: даже если LLM ниже упадёт, SERP уже сохранён
                $io->text(sprintf('  SERP: %d URL, %d article', count($serpResults), count($articleTexts)));
            }

            $gapSummary = '';
            if ($articleTexts !== []) {
                try {
                    $gapSummary = $this->llm->extractCompetitorTopics($query, $articleTexts);
                } catch (\Throwable $e) {
                    $io->text('  LLM извлечение тем не удалось (' . mb_substr($e->getMessage(), 0, 80) . ') — статус остаётся scanned, доснимется в следующий прогон');
                    $this->em->clear();
                    continue;
                }
            }

            $topicsCount = $gapSummary === '' ? 0 : count(explode("\n", $gapSummary));
            // Эвристика (калибруется после первого прогона на реальных данных, docs/seo_competitor_content.md):
            // больше показов и отсутствие нашей страницы в выдаче — выше приоритет; больше выявленных
            // тем — тоже выше (есть что раскрыть). Не переусложняем.
            $priority = ((float) $p['row']['shows']) * ($scan->getOurUrl() === null ? 2.0 : 1.0) + $topicsCount * 10.0;

            $scan->setGapSummary($gapSummary !== '' ? $gapSummary : null)
                ->setPriorityScore($priority)
                ->setStatus(SeoCompetitorScan::STATUS_ANALYZED);
            $this->em->flush();

            $route = $this->recommendRoute($p['intent'], $query, (string) $scan->getId(), $anchorNeedles, $styleNeedles);
            $report[] = [
                'keyword'  => $query,
                'intent'   => $p['intent'],
                'our_url'  => $scan->getOurUrl(),
                'articles' => count($articleTexts),
                'priority' => $priority,
                'route'    => $route,
            ];
            $io->text(sprintf('  готово: %d тем, priority=%.1f → %s', $topicsCount, $priority, $route));

            $this->em->clear();
        }

        if ($report === []) {
            $io->warning('Ни одна фраза не была полностью проанализирована (пустая выдача/потолок API/сбои LLM).');
            return Command::SUCCESS;
        }

        usort($report, static fn (array $a, array $b) => $b['priority'] <=> $a['priority']);
        $io->table(
            ['Фраза', 'Интент', 'our_url', 'Статей', 'Priority', 'Рекомендация'],
            array_map(static fn (array $r) => [
                mb_substr($r['keyword'], 0, 40), $r['intent'], $r['our_url'] !== null ? mb_substr($r['our_url'], 0, 30) : '—',
                $r['articles'], sprintf('%.1f', $r['priority']), $r['route'],
            ], $report),
        );

        if ($notify && $this->notifier->isEnabled()) {
            $this->notifier->send($this->formatDigest($report));
        }

        return Command::SUCCESS;
    }

    /** @return list<array{query:string,shows:int,source:string,page:?string}> */
    private function resolveCandidates(string $source, int $poolLimit): array
    {
        if ($source === 'wordstat') {
            return $this->mergeCandidates($this->fetchWordstatRows($poolLimit));
        }

        $rows = [];
        foreach ($this->gapProvider->resolveBands('both') as $band) {
            $rows = array_merge($rows, $this->gapProvider->fetchBandRows($band, $source, self::MIN_SHOWS, $poolLimit));
        }

        return $this->mergeCandidates($rows);
    }

    /** @return list<array{query:string,shows:int,source:string,page:?string}> */
    private function fetchWordstatRows(int $limit): array
    {
        try {
            $rows = $this->db->fetchAllAssociative(
                "SELECT keyword AS query, MAX(monthly_shows) AS shows
                 FROM brand_keyword
                 WHERE type = 'origin' AND monthly_shows IS NOT NULL
                 GROUP BY keyword
                 ORDER BY shows DESC LIMIT " . $limit,
            );
        } catch (\Throwable) {
            return [];
        }

        return array_map(
            static fn (array $r) => ['query' => (string) $r['query'], 'shows' => (int) $r['shows'], 'source' => SeoCompetitorScan::SOURCE_WORDSTAT, 'page' => null],
            $rows,
        );
    }

    /**
     * Дедуп фраз по нижнему регистру: одна и та же фраза из yandex-строки и gsc-строки
     * (--source=both) не должна оплачиваться/сканироваться дважды — сливаем в
     * demand_source='both', показы берём максимумом (разные шкалы источников, не суммируем).
     *
     * @param list<array{query:string,shows:int,source:string,page:?string}> $rows
     * @return list<array{query:string,shows:int,source:string,page:?string}>
     */
    private function mergeCandidates(array $rows): array
    {
        $merged = [];
        foreach ($rows as $r) {
            $key = mb_strtolower(trim($r['query']));
            if ($key === '') {
                continue;
            }
            if (!isset($merged[$key])) {
                $merged[$key] = ['query' => trim($r['query']), 'shows' => $r['shows'], 'sources' => [$r['source']], 'page' => $r['page']];
                continue;
            }
            $merged[$key]['shows'] = max($merged[$key]['shows'], $r['shows']);
            if (!in_array($r['source'], $merged[$key]['sources'], true)) {
                $merged[$key]['sources'][] = $r['source'];
            }
            $merged[$key]['page'] ??= $r['page'];
        }

        $out = [];
        foreach ($merged as $m) {
            $out[] = [
                'query'  => $m['query'],
                'shows'  => $m['shows'],
                'source' => count($m['sources']) > 1 ? SeoCompetitorScan::SOURCE_BOTH : $m['sources'][0],
                'page'   => $m['page'],
            ];
        }
        usort($out, static fn (array $a, array $b) => $b['shows'] <=> $a['shows']);

        return $out;
    }

    /**
     * Хосты официальных сайтов брендов (brand_link.link_type='website'), без «www.» —
     * для CompetitorPageClassifier::classify(). Удалённые/неактивные ссылки не считаем.
     *
     * @return string[]
     */
    private function officialHosts(): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT link_url FROM brand_link WHERE link_type = 'website' AND status != 'deleted' AND link_url IS NOT NULL",
        );

        $hosts = [];
        foreach ($rows as $r) {
            $host = strtolower((string) parse_url((string) $r['link_url'], PHP_URL_HOST));
            if ($host === '') {
                continue;
            }
            $host = preg_replace('/^www\./', '', $host) ?? $host;
            $hosts[$host] = true;
        }

        return array_keys($hosts);
    }

    /**
     * Классифицирует каждый URL выдачи; article-результаты получают текст (кэш
     * CompetitorArticle, 30-дневный, или свежий скрейп). Flush ОДИН раз после цикла —
     * иначе id новых CompetitorArticle ещё не назначены (AUTO_INCREMENT).
     *
     * @param array<int,array{url:string,title:string,content:string}> $serp
     * @param string[] $officialHosts
     * @return array{0:list<array{url:string,position:int,page_type:string,competitor_article_id:?int}>,1:list<string>}
     */
    private function buildSerpResults(array $serp, array $officialHosts, \DateTimeImmutable $now): array
    {
        $items = [];
        foreach (array_values($serp) as $idx => $r) {
            $pageType = $this->classifier->classify($r['url'], $officialHosts);
            $article  = $pageType === CompetitorPageClassifier::TYPE_ARTICLE
                ? $this->fetchOrReuseArticle($r['url'], $r['title'] ?? null, $now)
                : null;
            $items[] = ['position' => $idx + 1, 'url' => $r['url'], 'page_type' => $pageType, 'article' => $article];
        }
        $this->em->flush();

        $serpResults = [];
        $articleTexts = [];
        foreach ($items as $it) {
            $article = $it['article'];
            unset($it['article']);
            $it['competitor_article_id'] = $article?->getId();
            $serpResults[] = $it;
            if ($article !== null && $article->getContent() !== null && trim($article->getContent()) !== '') {
                $articleTexts[] = $article->getContent();
            }
        }

        return [$serpResults, $articleTexts];
    }

    /**
     * Тексты article-конкурентов из УЖЕ сохранённого serp_results (доснятие после сбоя
     * LLM на предыдущем прогоне) — без повторного скрейпа/поиска.
     *
     * @param list<array{url:string,position:int,page_type:string,competitor_article_id:?int}> $serpResults
     * @return list<string>
     */
    private function articleTextsFromSerpResults(array $serpResults): array
    {
        $texts = [];
        foreach ($serpResults as $r) {
            if (($r['page_type'] ?? null) !== CompetitorPageClassifier::TYPE_ARTICLE || empty($r['competitor_article_id'])) {
                continue;
            }
            $article = $this->em->find(CompetitorArticle::class, (int) $r['competitor_article_id']);
            if ($article !== null && $article->getContent() !== null && trim($article->getContent()) !== '') {
                $texts[] = $article->getContent();
            }
        }

        return $texts;
    }

    /** 30-дневный кэш по URL (аналогия WebScraperService, см. CLAUDE.md) — не перескрейпим свежее. */
    private function fetchOrReuseArticle(string $url, ?string $serpTitle, \DateTimeImmutable $now): ?CompetitorArticle
    {
        $existing = $this->articleRepo->findByUrl($url);
        if ($existing !== null && $existing->isFresh($now, self::FRESH_DAYS)) {
            return $existing;
        }

        // keepTables: true — trafilatura markdown сохраняет заголовки «##» (структурный
        // сигнал для extractCompetitorTopics), обычный режим их выкидывает.
        $fetched = $this->scraper->fetchCleanTextWithStatus($url, keepTables: true);
        $text = $fetched['text'];

        $article = $existing ?? new CompetitorArticle();
        if ($existing === null) {
            $article->setUrl($url)->setDomain(strtolower((string) parse_url($url, PHP_URL_HOST)));
        }
        $article->setPageType(CompetitorPageClassifier::TYPE_ARTICLE)
            ->setTitle($serpTitle !== null && trim($serpTitle) !== '' ? $serpTitle : $article->getTitle())
            ->setContent($text)
            ->setWordCount($text !== null ? (int) preg_match_all('/\p{L}+/u', $text) : null)
            ->setHttpStatus($fetched['httpStatus'])
            ->setFetchedAt($now);
        $this->em->persist($article);

        return $article;
    }

    /**
     * Рекомендованная команда для роутинга (docs/seo_competitor_content.md, «Роутинг»).
     * geo_category/other печатают плейсхолдер бренда/ниши — скан не знает, каким брендом
     * закрыть фразу, только город/стиль по тексту запроса.
     *
     * @param array<string,list<string>> $anchorNeedles
     * @param array<string,string> $styleNeedles
     */
    private function recommendRoute(string $intent, string $query, string $scanId, array $anchorNeedles, array $styleNeedles): string
    {
        $city = $this->extractCity($query);

        if ($intent === SeoCompetitorScan::INTENT_GEO_CATEGORY) {
            return sprintf(
                'app:seo:listicle <BRAND_ID> <STYLE_SLUG>%s --gap-context=%s (подставьте бренд/нишу вручную)',
                $city !== null ? sprintf(' --city=%s', $city) : '',
                $scanId,
            );
        }

        if ($intent === SeoCompetitorScan::INTENT_REPLACE_COMPARISON) {
            $anchorSlug = $this->matchNeedle($query, $anchorNeedles);

            return $anchorSlug !== null
                ? sprintf('app:seo:replace-listicle --anchor=%s --gap-context=%s', $anchorSlug, $scanId)
                : 'нет готового пути — якорь не найден в replacement_anchors.yaml, нужна курация';
        }

        // other
        $styleSlug = $this->matchNeedle($query, $styleNeedles);
        if ($styleSlug !== null) {
            return sprintf('app:seo:guide %s%s --gap-context=%s', $styleSlug, $city !== null ? sprintf(' --city=%s', $city) : '', $scanId);
        }

        return 'нет готового пути — нужна курация';
    }

    private function extractCity(string $query): ?string
    {
        $q = mb_strtolower($query);
        foreach (self::CITY_CANONICAL as $needle => $canonical) {
            if (mb_strpos($q, $needle) !== false) {
                return $canonical;
            }
        }

        return null;
    }

    /** @param array<string,list<string>|string> $needles slug => needle(s), сравнение по вхождению подстроки */
    private function matchNeedle(string $query, array $needles): ?string
    {
        $q = mb_strtolower($query);
        foreach ($needles as $slug => $needle) {
            foreach ((array) $needle as $n) {
                if ($n !== '' && mb_stripos($q, $n) !== false) {
                    return $slug;
                }
            }
        }

        return null;
    }

    /**
     * Якоря replacement_anchors.yaml → игольные строки для матча (slug X + его название
     * из БД, если бренд X существует) — фраза в выдаче обычно называет X по имени,
     * а не по латинскому slug'у файла.
     *
     * @return array<string,list<string>>
     */
    private function loadAnchorNeedles(): array
    {
        $file = \dirname(__DIR__, 2) . self::ANCHORS_FILE;
        if (!is_file($file)) {
            return [];
        }

        $data = Yaml::parseFile($file);
        $out = [];
        foreach (($data['anchors'] ?? []) as $a) {
            $slug = trim((string) ($a['foreign'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $needles = [mb_strtolower($slug)];
            /** @var Brand|null $brand */
            $brand = $this->em->getRepository(Brand::class)->findOneBy(['slug' => $slug]);
            if ($brand !== null && $brand->getTitle()) {
                $needles[] = mb_strtolower((string) $brand->getTitle());
            }
            $out[$slug] = array_values(array_unique($needles));
        }

        return $out;
    }

    /** @return array<string,string> slug => заголовок стиля в нижнем регистре, для матча 'other' на app:seo:guide */
    private function loadStyleNeedles(): array
    {
        $out = [];
        /** @var BrandStyle $style */
        foreach ($this->em->getRepository(BrandStyle::class)->findAll() as $style) {
            $title = trim((string) $style->getTitle());
            $slug  = $style->getSlug();
            if ($title !== '' && $slug !== null) {
                $out[$slug] = mb_strtolower($title);
            }
        }

        return $out;
    }

    /**
     * Компактная HTML-сводка под Telegram (parse_mode=HTML) — топ-N по priority,
     * тем же стилем, что SeoGapReportCommand::formatDigest.
     *
     * @param list<array{keyword:string,intent:string,our_url:?string,articles:int,priority:float,route:string}> $report
     */
    private function formatDigest(array $report, int $topN = 8, int $charCap = 1800): string
    {
        $lines = [sprintf('<b>SEO · разведка конкурентов · %s</b> (%d фраз)', (new \DateTime('now', new \DateTimeZone('Europe/Moscow')))->format('d.m'), count($report))];

        foreach (array_slice($report, 0, $topN) as $r) {
            $lines[] = sprintf(
                "\n• <b>%s</b> [%s] priority=%.1f, %d статей конкурентов\n  ↳ %s",
                htmlspecialchars($r['keyword']),
                htmlspecialchars($r['intent']),
                $r['priority'],
                $r['articles'],
                htmlspecialchars($r['route']),
            );
        }

        $msg = implode("\n", $lines);
        if (mb_strlen($msg) > $charCap) {
            $msg = mb_substr($msg, 0, $charCap - 1) . '…';
        }

        return $msg;
    }
}
