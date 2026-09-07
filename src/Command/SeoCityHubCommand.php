<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandContentRevision;
use App\Entity\CityHub;
use App\Entity\CityHubRevision;
use App\Repository\BrandRepository;
use App\Repository\CityHubRepository;
use App\Repository\CityHubRevisionRepository;
use App\Service\ArticleQaService;
use App\Service\BrandContentVersioner;
use App\Service\CitySlugger;
use App\Service\LlmService;
use App\Service\Seo\SpellChecker;
use App\Service\NearDuplicateDetector;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SEO/GEO: наполняет CityHub (кураторский intro+FAQ+мета городской посадочной
 * /{_locale}/cities/{slug}) grounded-контентом из состава каталога. Хабов сейчас
 * 4 (докладка топ-4 городов по GSC-показам) — у остальных десятков городов страница
 * падает на формульный fallback и теряет позиции (docs/geo_city_demand_2026_09.md).
 *
 * Факты — ТОЛЬКО из БД (число брендов, их title/anons, топ-5 стилей), без скрейпа.
 * Поисковые фразы — из gsc_query_stats/yandex_query_stats по алиасам города, вплетаются
 * в промпт (LlmService::generateCityHub). QA-гейт — тот же ArticleQaService, что
 * использует app:brand:generate-content (article-qa-toolkit, fail-open).
 *
 *   php bin/console app:seo:city-hub --city=Новосибирск --dry-run
 *   php bin/console app:seo:city-hub 10 --min-brands=5 --no-debug
 */
#[AsCommand(
    name: 'app:seo:city-hub',
    description: 'SEO/GEO: контент гео-хаба города (intro + FAQ + мета) из фактов каталога',
)]
class SeoCityHubCommand extends Command
{
    private const MAX_BRANDS_IN_FACTS = 20;   // сколько брендов перечислить в фактах для LLM
    private const MAX_ANONS_LEN       = 160;  // обрезка anons в фактах
    private const MAX_PHRASES         = 12;   // поисковых фраз в промпт

    // Гейт по plain-тексту intro (strip_tags), не по HTML — HTML тегов набегает
    // на треть длины и порог по нему врёт (замер существующих хабов: HTML 1250 ≈ текст 691).
    // Пол взят по факту: самый короткий ЖИВОЙ хаб — Екатеринбург, 456 символов текста,
    // и он держит позицию 8.5 при 977 показах. Порог выше этого браковал бы контент,
    // который работает (у городов с 5 брендами фактов на длинный текст просто нет).
    private const MIN_INTRO_PLAIN_LEN = 450;
    private const MAX_INTRO_PLAIN_LEN = 2500;
    private const MIN_FAQ_PAIRS       = 2;

    /**
     * Штампы, которые промпт запрещает, а модель всё равно вставляет («широкий
     * ассортимент» пролез при overall 90.6 — модуль AI-почерка тулкита их не блокирует,
     * только снижает балл).
     */
    private const CLICHES = [
        'широкий ассортимент', 'мир моды', 'идеальный выбор', 'на любой вкус',
        'уникальный стиль', 'своя специфика', 'важная часть локальной индустрии',
    ];

    /**
     * Латинский токен от 5 символов: апостроф и цифры внутри сохраняем, иначе
     * KUL'TURA рвётся на «KUL»/«TURA», а DE4444TH — на «DE»/«TH» (все короче порога),
     * и подмены «KUL'TARS»/«DE444TH» проскакивают.
     */
    private const LATIN_WORD_RE = "/[A-Za-z][A-Za-z0-9']{4,}/";

    /**
     * Мусор, который локальная модель выдаёт регулярно и который дороже ловить глазами,
     * чем регуляркой (все три случая — из первого прогона по 7 городам):
     *   - пересказ семантики в теле текста («пользователи часто ищут одежду abda в Казани»),
     *   - символы чужих алфавитов посреди слова («в катало지가 WEARBASE»),
     *   - артефакты экранирования из anons («Master Of Chillin'''»).
     */
    private const JUNK_PATTERNS = [
        'пересказ поисковых запросов' => '/поисковы[ех]\s+запрос|популярн\w+\s+запрос|пользовател\w+\s+(часто\s+)?ищ|многие\s+ищ\w*\s+.{0,30}(через\s+)?наш|добавля\w+\s+к\s+запрос|поиск\s+\S+\s+.{0,40}приводит|запрос\w*\s+(касается|звучит)/iu',
        'символы чужих алфавитов'     => '/[\x{0370}-\x{03FF}\x{0530}-\x{058F}\x{0590}-\x{05FF}\x{0600}-\x{06FF}\x{0E00}-\x{0E7F}\x{1100}-\x{11FF}\x{3040}-\x{30FF}\x{3130}-\x{318F}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]/u',
        'артефакт экранирования'      => "/'{3,}|\\\\{2,}/u",
    ];

    /**
     * Блокирующий порог — ЭТО overall, НЕ $qa['passed'] сервиса. Прогон
     * tools/article-qa-toolkit на 4 живых хабах (docs/geo_city_demand_2026_09.md §8)
     * показал passed=false у ВСЕХ них (Human-likeness 7.0 < порога сервиса 8.0),
     * включая sankt-peterburg — ту самую страницу с 2329 показов/поз.8.4. Сервис
     * откалиброван на длинные описания брендов (~1900 симв., HL 8.2–8.5), а не на
     * 500–800-символьные intro хабов. Гейтить их по $qa['passed'] значит отбраковывать
     * контент лучше уже работающего. MIN_QA_OVERALL — эмпирический пол по замеру:
     * живые хабы 77.9–84.3, описания брендов 86.7–87.7. Не возвращай сюда $qa['passed'].
     */
    private const MIN_QA_OVERALL = 80.0;

    /** 1.5× запас над худшим intro-Jaccard текущего корпуса (0.235, см. docs). */
    private const MAX_JACCARD = 0.35;

    /**
     * meta_description судим мягче: это 160 символов служебного текста, по функции
     * шаблонного («город + стили + каталог»), и Google его всё равно переписывает под
     * запрос. У четырёх рукописных хабов попарный Jaccard меты доходит до 0.375 —
     * то есть 0.35 отбраковывает то, что человек написал руками. Риск scaled content
     * живёт в intro (там порог общий, и фактические значения 0.01–0.02).
     */
    private const MAX_JACCARD_META = 0.55;

    /**
     * FAQ — тоже мягче intro, но строже меты. Три вопроса про бренды одного города
     * лексически пересекаются по устройству задачи («что шьёт X», «какие ещё марки
     * города есть»), и на пороге 0.35 Краснодар давал 0.35–0.36 три попытки подряд,
     * хотя intro у него 0.01. Рукописного корпуса FAQ для сравнения нет вообще (у
     * четырёх живых хабов FAQ не было), так что эмпирический ориентир — сами прогоны:
     * принятые тексты давали 0.15–0.32. Порог 0.45 оставляет запас над ними и всё
     * ещё ловит настоящие дубли (полностью шаблонный набор даёт >0.6). Риск scaled
     * content живёт в intro, и там порог не тронут.
     */
    private const MAX_JACCARD_FAQ = 0.45;

    /** Короче — детектор на 3-граммах слеп (пересечение пустое), считаем униграммами. */
    private const SHORT_TEXT_WORDS = 15;

    /**
     * Алиасы для поиска фраз в gsc_query_stats/yandex_query_stats: пользователи пишут
     * «питер», «мск», «екб» вместо полного названия. Для городов вне списка — падаем
     * на defaultAlias() (см. ниже).
     */
    private const CITY_ALIASES = [
        'Санкт-Петербург'  => ['питер', 'спб', 'санкт'],
        'Москва'           => ['москв', 'мск'],
        'Екатеринбург'     => ['екатеринбург', 'екб'],
        'Нижний Новгород'  => ['нижн', 'нижегород'],
        'Ростов-на-Дону'   => ['ростов'],
    ];

    private int $processed  = 0;
    private int $saved      = 0;
    private int $gateFailed = 0;
    private int $llmErrors  = 0;

    // Корпус для near-dup: существующие CityHub (заполняется один раз в execute())
    // плюс хабы, уже принятые в ЭТОМ прогоне — чтобы 7 городов, генерируемых одним
    // промптом из однотипных фактов, не расползлись в scaled content между собой.
    // slug => текст; отдельно по каждому полю, сравниваем только поле-к-полю.
    /** @var array<string,string> */
    private array $existingIntro = [];
    /** @var array<string,string> */
    private array $existingMeta = [];
    /** @var array<string,string> */
    private array $existingFaq = [];
    /** @var array<string,string> */
    private array $generatedIntro = [];
    /** @var array<string,string> */
    private array $generatedMeta = [];
    /** @var array<string,string> */
    private array $generatedFaq = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LlmService $llm,
        private readonly CitySlugger $slugger,
        private readonly ArticleQaService $articleQa,
        private readonly SpellChecker $speller,
        private readonly NearDuplicateDetector $nearDup,
        private readonly CityHubRevisionRepository $hubRevisions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, 'Максимум городов за прогон', 5)
            ->addOption('city', null, InputOption::VALUE_REQUIRED, 'Один город по названию (как в brand.city)')
            ->addOption('min-brands', null, InputOption::VALUE_REQUIRED, 'Минимум активных брендов в городе (тонкая страница — не индексируем)', 5)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Перезаписать уже существующий CityHub (QA-гейт --force не отключает)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Не сохранять, показать результат в консоли')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $limit     = max(1, (int) $input->getArgument('limit'));
        $cityOpt   = $input->getOption('city');
        $minBrands = max(1, (int) $input->getOption('min-brands'));
        $force     = (bool) $input->getOption('force');
        $dryRun    = (bool) $input->getOption('dry-run');

        $io->title('SEO/GEO · контент городских хабов');
        if ($dryRun) {
            $io->note('dry-run — без сохранения');
        }

        foreach ($this->em->getRepository(CityHub::class)->findAll() as $hub) {
            $this->existingIntro[$hub->getSlug()] = strip_tags((string) $hub->getIntro());
            $this->existingMeta[$hub->getSlug()]  = (string) $hub->getMetaDescription();
            $this->existingFaq[$hub->getSlug()]   = implode(' ', array_column($hub->getFaq() ?? [], 'question'));
        }

        $cities = $cityOpt !== null
            ? [(string) $cityOpt]
            : $this->selectCities($limit, $minBrands, $force);

        if ($cities === []) {
            $io->success('Нет городов-кандидатов (у всех подходящих уже есть CityHub, либо брендов мало).');
            return Command::SUCCESS;
        }

        foreach ($cities as $city) {
            $this->processCity($city, $minBrands, $force, $dryRun, $io);
        }

        $io->newLine();
        $io->table(['Результат', 'Кол-во'], [
            ['Города обработано', $this->processed],
            ['Сохранено', $this->saved],
            ['Не прошло гейт', $this->gateFailed],
            ['Ошибок LLM', $this->llmErrors],
        ]);

        return Command::SUCCESS;
    }

    /**
     * Кандидаты: города с ≥minBrands активных не-иностранных брендов, у которых ещё
     * нет CityHub (--force снимает это условие), по убыванию числа брендов.
     *
     * @return string[]
     */
    private function selectCities(int $limit, int $minBrands, bool $force): array
    {
        /** @var BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);
        $qb = $repo->createQueryBuilder('b')
            ->select('b.city AS city, COUNT(b.id) AS cnt')
            ->where('b.status = :status')
            ->andWhere('b.city IS NOT NULL')
            ->andWhere("b.city != ''")
            ->setParameter('status', Statuses::Active)
            ->groupBy('b.city')
            ->having('cnt >= :minBrands')
            ->setParameter('minBrands', $minBrands)
            ->orderBy('cnt', 'DESC');
        $repo->excludeForeignOrigin($qb);
        $rows = $qb->getQuery()->getResult();

        $existingSlugs = $force ? [] : array_flip(
            $this->em->getRepository(CityHub::class)->createQueryBuilder('c')
                ->select('c.slug')
                ->getQuery()
                ->getSingleColumnResult(),
        );

        $cities = [];
        foreach ($rows as $row) {
            $slug = $this->slugger->slugify($row['city']);
            if (isset($existingSlugs[$slug])) {
                continue;
            }
            $cities[] = $row['city'];
            if (count($cities) >= $limit) {
                break;
            }
        }

        return $cities;
    }

    private function processCity(string $city, int $minBrands, bool $force, bool $dryRun, SymfonyStyle $io): void
    {
        $this->processed++;
        $io->section($city);

        /** @var BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);
        $brandsQb = $repo->createQueryBuilder('b')
            ->where('b.status = :status')
            ->andWhere('b.city = :city')
            ->setParameter('status', Statuses::Active)
            ->setParameter('city', $city)
            // Ниша-гейт: страница пока показывает и off-niche (кондитерские фабрики
            // среди одежды — известная проблема бэкафилла), но в ФАКТЫ для LLM их
            // пускать нельзя: модель добросовестно опишет «производство сладостей»
            // в тексте про бренды одежды.
            ->andWhere("b.nicheStatus IS NULL OR b.nicheStatus != 'off'")
            ->orderBy('b.title', 'ASC');
        $repo->excludeForeignOrigin($brandsQb);
        $brands = $brandsQb->getQuery()->getResult();

        if (count($brands) < $minBrands) {
            $io->text(sprintf('  пропуск: %d активных брендов < %d (тонкая страница)', count($brands), $minBrands));
            return;
        }

        $slug = $this->slugger->slugify($city);
        /** @var CityHubRepository $hubRepo */
        $hubRepo  = $this->em->getRepository(CityHub::class);
        $existing = $hubRepo->findOneBy(['slug' => $slug]);
        if ($existing !== null && !$force) {
            $io->text('  пропуск: CityHub уже есть (--force для перезаписи)');
            return;
        }

        $facts    = $this->collectFacts($city, $brands, $repo);
        $phrases  = $this->collectPhrases($city, $brands);

        try {
            $result = $this->llm->generateCityHub($city, $facts, $phrases);
        } catch (\Throwable $e) {
            $io->warning('  LLM ошибка: ' . $e->getMessage());
            $this->llmErrors++;
            return;
        }

        // Детерминированная вычитка (Yandex Speller) до гейта: модель регулярно даёт
        // согласование вида «в каталога представлены», а это не ловится ни баллом
        // тулкита, ни регуляркой. Названия брендов отдаём в protected — их автоправка
        // не касается. Тот же приём, что в app:seo:replace-listicle.
        $protected = array_map(static fn(Brand $b) => (string) $b->getTitle(), $brands);
        $spellFixes = 0;
        foreach (['intro', 'meta_description', 'h1', 'meta_title'] as $field) {
            if (($result[$field] ?? null) === null) {
                continue;
            }
            $result[$field] = $this->spellFix((string) $result[$field], $protected, $spellFixes);
        }
        foreach ($result['faq'] as $i => $pair) {
            foreach (['question', 'answer'] as $key) {
                $result['faq'][$i][$key] = $this->spellFix($pair[$key], $protected, $spellFixes);
            }
        }
        if ($spellFixes > 0) {
            $io->text(sprintf('  орфография: исправлено %d', $spellFixes));
        }

        $gate = $this->checkGate($result, $phrases, $facts);
        $overallStr = $gate['overall'] !== null ? sprintf('%.1f', $gate['overall']) : '?';
        if (!$gate['passed']) {
            $io->warning(sprintf(
                '  не прошло гейт (intro %d симв., FAQ %d пар, overall %s): %s',
                $gate['plainLen'],
                $gate['faqCount'],
                $overallStr,
                implode('; ', $gate['reasons']),
            ));
            $this->gateFailed++;
            return;
        }
        $io->text(sprintf('  QA: overall %s, intro %d симв., FAQ %d пар', $overallStr, $gate['plainLen'], $gate['faqCount']));
        // SB/HL/passed сервиса — НЕ блокирующие (см. MIN_QA_OVERALL), но печатаем для
        // сравнения с уже живыми хабами (все 4 тоже ниже пола HL=8.0 сервиса).
        $qa = $gate['qa'];
        if ($qa !== null && $qa['checked']) {
            $io->text(sprintf(
                '  QA детали: SB %s, HL %s%s',
                isset($qa['metrics']['spambrain']) ? sprintf('%.1f', $qa['metrics']['spambrain']) : '?',
                isset($qa['metrics']['human_likeness']) ? sprintf('%.1f', $qa['metrics']['human_likeness']) : '?',
                $qa['passed'] ? '' : ' — ниже пола описаний брендов: ' . implode('; ', $qa['reasons']),
            ));
        }

        $metaText = (string) $result['meta_description'];
        $faqText  = implode(' ', array_column($result['faq'], 'question'));
        $dup      = $this->checkNearDup($slug, $gate['plain'], $metaText, $faqText);
        $io->text(sprintf(
            '  near-dup: intro %.2f (%s), meta %.2f (%s), FAQ %.2f (%s)',
            $dup['intro']['score'], $dup['intro']['slug'] ?? '—',
            $dup['meta_description']['score'], $dup['meta_description']['slug'] ?? '—',
            $dup['FAQ']['score'], $dup['FAQ']['slug'] ?? '—',
        ));
        if ($dup['failedField'] !== null) {
            $f = $dup['failedField'];
            $io->warning(sprintf('  near-dup: %s совпал с «%s» (Jaccard %.2f) — пропуск', $f, $dup[$f]['slug'], $dup[$f]['score']));
            $this->gateFailed++;
            return;
        }

        // Город принят — попадает в пул сравнения для ОСТАЛЬНЫХ городов этого же
        // прогона (независимо от --dry-run: near-dup — проверка контента, не БД).
        $this->generatedIntro[$slug] = $gate['plain'];
        $this->generatedMeta[$slug]  = $metaText;
        $this->generatedFaq[$slug]   = $faqText;

        if ($dryRun) {
            $io->text('  h1: ' . ($result['h1'] ?? '—'));
            $io->text('  meta_title: ' . ($result['meta_title'] ?? '—'));
            $io->text('  meta_description: ' . ($result['meta_description'] ?? '—'));
            $io->text('  intro: ' . $result['intro']);
            foreach ($result['faq'] as $i => $pair) {
                $io->text(sprintf('  FAQ %d: %s', $i + 1, $pair['question']));
            }
            return;
        }

        $hub = $existing ?? new CityHub();

        // closed-loop (см. docs/geo_city_demand_2026_09.md §8): overall — не гейт входа
        // для коротких intro хабов, реальная приёмка — исход по GSC/Яндексу с откатом.
        // Снимаем baseline (если истории ещё нет) СНАЧАЛА, контентом хаба ДО перезаписи —
        // для новых городов он пуст, это нормально: фиксирует, что тут был формульный fallback.
        $hasHistory = $existing !== null && $this->hubRevisions->hasAny($existing);
        $prevActive = $hasHistory ? $this->hubRevisions->findActive($existing) : null;
        if ($hasHistory) {
            $prevActive?->setActive(false);
        } else {
            $baseline = (new CityHubRevision())
                ->setHub($hub)
                ->setSlug($slug)
                ->setH1($hub->getH1())
                ->setMetaTitle($hub->getMetaTitle())
                ->setMetaDescription($hub->getMetaDescription())
                ->setIntro($hub->getIntro())
                ->setFaq($hub->getFaq())
                ->setSource(CityHubRevision::SOURCE_MANUAL)
                ->setActive(false)
                ->setVerdict(BrandContentRevision::VERDICT_WIN) // baseline — не эксперимент
                ->setMeasureAfter(null);
            $this->em->persist($baseline);
        }

        [$imprBefore, $clicksBefore, $indexedBefore] = $this->hubRevisions->citySnapshot($slug);
        $attempt = $existing !== null ? $this->hubRevisions->countGenerated($existing) + 1 : 1;

        $revision = (new CityHubRevision())
            ->setHub($hub)
            ->setSlug($slug)
            ->setH1($result['h1'])
            ->setMetaTitle($result['meta_title'])
            ->setMetaDescription($result['meta_description'])
            ->setIntro($result['intro'])
            ->setFaq($result['faq'])
            ->setSource(CityHubRevision::SOURCE_GENERATED)
            ->setQaOverall($gate['overall'])
            ->setActive(true)
            ->setAttempt($attempt)
            ->setPrevRevisionId($prevActive?->getId())
            ->setVerdict(BrandContentRevision::VERDICT_PENDING)
            ->setMeasureAfter((new \DateTime())->modify('+' . BrandContentVersioner::windowDays($attempt) . ' days'))
            ->setGscImprBefore($imprBefore)
            ->setGscClicksBefore($clicksBefore)
            ->setGscIndexedBefore($indexedBefore);
        $this->em->persist($revision);

        $hub->setSlug($slug)
            ->setTitle($city)
            ->setH1($result['h1'])
            ->setMetaTitle($result['meta_title'])
            ->setMetaDescription($result['meta_description'])
            ->setIntro($result['intro'])
            ->setFaq($result['faq'])
            ->setIsActive(true);
        $this->em->persist($hub);
        $this->em->flush();
        $this->saved++;
        $io->text('  сохранено');
    }

    /**
     * Near-dup гейт (блокирующий): попарно против ВСЕХ существующих CityHub (кроме
     * самого себя — важно при --force) и против хабов, уже принятых в этом прогоне,
     * отдельно по intro/meta_description/склейке вопросов FAQ. Короткие тексты (< 15
     * слов, чаще всего meta_description) детектор на 3-граммах не видит — считаем
     * униграммами для той стороны пары, что короче. Считает МАКСИМУМ по каждому полю
     * (не выходит по первому совпадению) — чтобы печатать реальные цифры и на проходе,
     * не только на провале.
     *
     * @return array{
     *     intro: array{score: float, slug: ?string},
     *     meta_description: array{score: float, slug: ?string},
     *     FAQ: array{score: float, slug: ?string},
     *     failedField: ?string,
     * }
     */
    private function checkNearDup(string $ownSlug, string $introPlain, string $meta, string $faqText): array
    {
        $fields = [
            'intro'            => [$introPlain, $this->existingIntro, $this->generatedIntro],
            'meta_description' => [$meta, $this->existingMeta, $this->generatedMeta],
            'FAQ'              => [$faqText, $this->existingFaq, $this->generatedFaq],
        ];

        $result      = [];
        $failedField = null;
        foreach ($fields as $field => [$text, $existingPool, $generatedPool]) {
            $best     = 0.0;
            $bestSlug = null;
            foreach ([$existingPool, $generatedPool] as $pool) {
                foreach ($pool as $slug => $other) {
                    if ($slug === $ownSlug || trim($other) === '') {
                        continue;
                    }
                    $size  = min($this->wordCount($text), $this->wordCount($other)) < self::SHORT_TEXT_WORDS ? 1 : 3;
                    $score = $this->nearDup->similarity($text, $other, $size);
                    if ($score > $best) {
                        $best     = $score;
                        $bestSlug = $slug;
                    }
                }
            }
            $result[$field] = ['score' => $best, 'slug' => $bestSlug];
            $limit = match ($field) {
                'meta_description' => self::MAX_JACCARD_META,
                'FAQ'              => self::MAX_JACCARD_FAQ,
                default            => self::MAX_JACCARD,
            };
            if ($failedField === null && $best >= $limit) {
                $failedField = $field;
            }
        }
        $result['failedField'] = $failedField;

        return $result;
    }

    private function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim(strip_tags($text)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Факты для промпта — только то, что реально есть в каталоге: число брендов,
     * до 20 брендов (title + обрезанный anons), топ-5 стилей с числом брендов.
     *
     * @param Brand[] $brands
     */
    private function collectFacts(string $city, array $brands, BrandRepository $repo): string
    {
        $lines = [sprintf('Активных брендов одежды в городе: %d', count($brands))];

        foreach (array_slice($brands, 0, self::MAX_BRANDS_IN_FACTS) as $b) {
            // Битые названия («YUGE ЮДЖ *Y-----W )))») в факты не отдаём: модель их
            // перепишет в текст как есть. Признак — серия небуквенных символов подряд.
            if (preg_match('/[^\p{L}\p{N}\s]{3,}/u', (string) $b->getTitle()) === 1) {
                continue;
            }
            $anons = trim((string) $b->getAnons());
            $line  = (string) $b->getTitle();
            if ($anons !== '') {
                $line .= ' — ' . mb_substr($anons, 0, self::MAX_ANONS_LEN);
            }
            $lines[] = $line;
        }

        $stylesQb = $repo->createQueryBuilder('b')
            ->select('s.title AS title, COUNT(DISTINCT b.id) AS cnt')
            ->join('b.styles', 's')
            ->where('b.status = :status')
            ->andWhere('b.city = :city')
            ->setParameter('status', Statuses::Active)
            ->setParameter('city', $city)
            ->groupBy('s.id')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults(5);
        $repo->excludeForeignOrigin($stylesQb);
        $styles = $stylesQb->getQuery()->getResult();
        if ($styles !== []) {
            $lines[] = 'Топ стилей: ' . implode(', ', array_map(
                static fn(array $s) => sprintf('%s (%d)', $s['title'], $s['cnt']),
                $styles,
            ));
        }

        // Артефакты экранирования встречаются в самих данных (был бренд с title
        // «Master Of Chillin'''»). Их нельзя показывать модели: она перепишет артефакт
        // в текст, и его отбракует junk-гейт — то есть город застрянет из-за одного
        // битого поля. Чистим на входе, гейт остаётся страховкой на случай, когда мусор
        // сгенерировала уже сама модель.
        return preg_replace(["/'{2,}/u", '/\\\\{2,}/u'], ["'", '\\'], implode("\n", $lines)) ?? implode("\n", $lines);
    }

    /**
     * Поисковые фразы про этот город из gsc_query_stats (28 дней) + yandex_query_stats
     * (навигационные — с названием бренда внутри — выбрасываем, см. ниже)
     * (последний снэпшот), отфильтрованные по алиасам и слитые по убыванию показов.
     * Таблицы — read-only аналитика без ORM-сущностей, сырой SQL уместен (как в
     * app:seo:gap-report/aio-remediate).
     *
     * @return string[]
     */
    private function collectPhrases(string $city, array $brands): array
    {
        $aliases = self::CITY_ALIASES[$city] ?? [$this->defaultAlias($city)];
        $like    = array_map(static fn(string $a) => '%' . mb_strtolower($a) . '%', $aliases);
        $conn    = $this->em->getConnection();

        $rows = [];
        try {
            $where = implode(' OR ', array_fill(0, count($like), 'LOWER(query) LIKE ?'));
            $rows  = array_merge($rows, $conn->fetchAllAssociative(
                "SELECT query AS phrase, SUM(impressions) AS demand
                 FROM gsc_query_stats
                 WHERE day >= DATE_SUB(CURDATE(), INTERVAL 28 DAY) AND ({$where})
                 GROUP BY query",
                $like,
            ));
        } catch (\Throwable) {
            // таблицы нет / крон синка ещё не отработал — просто без GSC-фраз
        }

        try {
            $where = implode(' OR ', array_fill(0, count($like), 'LOWER(query_text) LIKE ?'));
            $rows  = array_merge($rows, $conn->fetchAllAssociative(
                "SELECT query_text AS phrase, shows AS demand
                 FROM yandex_query_stats
                 WHERE date_to = (SELECT MAX(date_to) FROM yandex_query_stats) AND ({$where})",
                $like,
            ));
        } catch (\Throwable) {
        }

        usort($rows, static fn(array $a, array $b) => (int) $b['demand'] <=> (int) $a['demand']);

        // Навигационные фразы («молотов одежда пермь») отсекаем: их закрывает карточка
        // бренда, а не хаб. В городах с тощим спросом такие фразы занимают половину пула
        // (Пермь: 2 из 4) — LLM их вставляла в intro почти дословно, получался переспам.
        // Сверяем со ВСЕМ каталогом, а не только с брендами города: у бренда Abda город
        // не заполнен, поэтому фраза «abda казань» проходила фильтр по городу и уезжала
        // в текст Казани.
        $brandTitles = array_values(array_filter(
            array_map(
                static fn(array $r) => mb_strtolower(trim((string) $r['title'])),
                $conn->fetchAllAssociative(
                    "SELECT DISTINCT title FROM brand WHERE status = 'active' AND CHAR_LENGTH(title) >= 4",
                ),
            ),
            static fn(string $t) => $t !== '',
        ));

        $seen = [];
        $phrases = [];
        foreach ($rows as $row) {
            $key = mb_strtolower(trim((string) $row['phrase']));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            foreach ($brandTitles as $title) {
                if (str_contains($key, $title)) {
                    continue 2;
                }
            }
            $phrases[] = (string) $row['phrase'];
            if (count($phrases) >= self::MAX_PHRASES) {
                break;
            }
        }

        return $phrases;
    }

    /**
     * Алиас по умолчанию для города вне CITY_ALIASES: город склоняется в поисковых
     * фразах («в Перми», «из Казани»), а падежная форма чаще всего меняет только
     * последнюю гласную/мягкий знак основы («Пермь» → «Перми», «Казань» → «Казани»),
     * согласный конец основы («Новосибирск» → «Новосибирске») не трогает. Поэтому
     * отрезаем последнюю букву только если это гласная/мягкий знак, иначе берём
     * слово целиком; короче 4 символов после обрезки — тоже целиком (иначе алиас
     * ловит случайный шум).
     */
    /**
     * Ищет латинское слово текста, которое ПОЧТИ совпадает с чем-то из ФАКТОВ, но не
     * совпадает точно, — то есть модель переписала название с ошибкой («KUL\'TARS»
     * вместо KUL\'TURA, «Drobyschena» вместо Drobysheva).
     *
     * Словарь строим по всему тексту фактов, а не только по названиям брендов: имена
     * товаров из anons («свитшоты BEIGE FOG») — тоже законная латиница, и по словарю
     * из одних названий BEIGE ловился как искажение BRIGHT. Расстояние 1–2: на 3 в
     * ложные срабатывания попадают обычные термины. Кириллицу не проверяем — там
     * склонения дают ту же дистанцию, что и опечатки.
     *
     * @return array{0: string, 1: string}|null [искажение, как было в фактах]
     */
    /**
     * Вычитка орфографии по абзацам. HTML целиком в Speller отдавать нельзя: теги
     * склеиваются со словами («<p>В» → один токен), API возвращает НОЛЬ ошибок и
     * вычитка молча становится пустышкой (проверено: тот же текст без тегов даёт
     * «каталога» → «каталоге»). Поэтому правим текст внутри каждого <p>.
     *
     * @param string[] $protected названия брендов — не автоправятся
     */
    private function spellFix(string $value, array $protected, int &$fixes): string
    {
        $fix = function (string $text) use ($protected, &$fixes): string {
            if (trim($text) === '') {
                return $text;
            }
            $checked = $this->speller->proofread($text, $protected);
            $applied = count(array_filter($checked['flags'], static fn(array $f) => $f['applied']));
            if ($applied === 0) {
                return $text;
            }
            $fixes += $applied;

            return $checked['fixed'];
        };

        if (!str_contains($value, '<p')) {
            return $fix($value);
        }

        return preg_replace_callback(
            '/(<p[^>]*>)(.*?)(<\/p>)/su',
            static fn(array $m) => $m[1] . $fix($m[2]) . $m[3],
            $value,
        ) ?? $value;
    }

    /**
     * Слово, в котором смешаны кириллица и латиница. Разрешено только если ровно так
     * написано в ФАКТАХ (бывают названия вида «YUGE ЮДЖ»).
     */
    private function findMixedScriptWord(string $text, string $facts): ?string
    {
        $allowed = [];
        preg_match_all('/\S*[A-Za-z]\S*[\x{0400}-\x{04FF}]\S*|\S*[\x{0400}-\x{04FF}]\S*[A-Za-z]\S*/u', $facts, $fm);
        foreach ($fm[0] as $w) {
            $allowed[mb_strtolower(trim($w, " \t\n.,;:!?()«»\"'"))] = true;
        }

        preg_match_all('/[\p{L}\x{0027}]{3,}/u', $text, $m);
        foreach (array_unique($m[0]) as $word) {
            $hasLatin = preg_match('/[A-Za-z]/', $word) === 1;
            $hasCyr   = preg_match('/[\x{0400}-\x{04FF}]/u', $word) === 1;
            if ($hasLatin && $hasCyr && !isset($allowed[mb_strtolower($word)])) {
                return $word;
            }
        }

        return null;
    }

    private function findDistortedName(string $text, string $facts): ?array
    {
        $vocab = [];
        preg_match_all(self::LATIN_WORD_RE, $facts, $fm);
        foreach ($fm[0] as $w) {
            $vocab[mb_strtolower($w)] = $w;
        }
        if ($vocab === []) {
            return null;
        }

        preg_match_all(self::LATIN_WORD_RE, $text, $m);
        foreach (array_unique($m[0]) as $word) {
            $lower = mb_strtolower($word);
            if (isset($vocab[$lower])) {
                continue; // ровно то, что дали в фактах
            }
            foreach ($vocab as $key => $original) {
                $d = levenshtein($lower, $key);
                if ($d >= 1 && $d <= 2 && abs(mb_strlen($lower) - mb_strlen($key)) <= 2) {
                    return [$word, $original];
                }
            }
        }

        return null;
    }

    private function defaultAlias(string $city): string
    {
        $word = mb_strtolower(trim(explode(' ', trim($city))[0] ?? ''));
        $last = mb_substr($word, -1);
        if (in_array($last, ['ь', 'а', 'я', 'о', 'е', 'ы', 'и', 'у', 'ю'], true)) {
            $stem = mb_substr($word, 0, -1);
            if (mb_strlen($stem) >= 4) {
                return $stem;
            }
        }

        return $word;
    }

    /**
     * Гейт качества (в порядке дешёвое → дорогое): intro должен быть заполнен и в
     * HTML, укладываться в диапазон длины по plain-тексту (НЕ по HTML — теги дают
     * лишнюю треть символов, см. замер существующих хабов в docs), иметь ≥2 пар FAQ,
     * и набрать ArticleQaService overall ≥ MIN_QA_OVERALL. Блокирует ИМЕННО overall,
     * не $qa['passed'] — см. комментарий у константы. $qa['checked'] === false (тулкит
     * недоступен) — fail-open как и в самом сервисе, не блокируем.
     *
     * @return array{passed: bool, reasons: string[], plainLen: int, faqCount: int, overall: ?float, plain: string, qa: ?array}
     */
    /**
     * @param string[] $phrases     фразы, отданные модели: дословных вхождений быть не должно
     * @param string   $facts       факты, отданные модели: латиница текста должна совпадать с ними
     */
    private function checkGate(array $result, array $phrases = [], string $facts = ''): array
    {
        $intro = $result['intro'];
        $faq   = $result['faq'];

        if ($intro === null || !str_contains($intro, '<p')) {
            return $this->gateFail(['intro пустой или без <p>'], 0, count($faq));
        }

        $plain    = trim(preg_replace('/\s+/u', ' ', strip_tags($intro)) ?? '');
        $plainLen = mb_strlen($plain);
        if ($plainLen < self::MIN_INTRO_PLAIN_LEN || $plainLen > self::MAX_INTRO_PLAIN_LEN) {
            return $this->gateFail(
                [sprintf('длина текста %d вне диапазона %d–%d', $plainLen, self::MIN_INTRO_PLAIN_LEN, self::MAX_INTRO_PLAIN_LEN)],
                $plainLen,
                count($faq),
            );
        }

        // Мусорные паттерны — по всему, что уедет на страницу, не только по intro.
        $everything = implode(' ', array_filter([
            $intro,
            $result['h1'] ?? null,
            $result['meta_title'] ?? null,
            $result['meta_description'] ?? null,
            implode(' ', array_column($faq, 'question')),
            implode(' ', array_column($faq, 'answer')),
        ]));
        // Дословное вхождение поисковой фразы = переспам («Поиск abda одежда казань
        // приводит к знакомству с местными мастерами»). Промпт это запрещает, но
        // модель срывается, поэтому проверяем механически по тем же фразам, что дали.
        $haystack = mb_strtolower(preg_replace('/\s+/u', ' ', $everything) ?? $everything);
        foreach ($phrases as $phrase) {
            $needle = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $phrase) ?? $phrase));
            if (mb_strlen($needle) >= 10 && str_word_count($needle, 0, 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя') >= 2
                && str_contains($haystack, $needle)) {
                return [
                    'passed'   => false,
                    'reasons'  => [sprintf('дословная поисковая фраза в тексте: «%s»', $phrase)],
                    'plainLen' => $plainLen,
                    'faqCount' => count($faq),
                    'overall'  => null,
                ];
            }
        }

        // Искажённые названия брендов («KUL'TARS» вместо KUL'TURA, «Drobyschena»
        // вместо Drobysheva). Промпт требует копировать названия символ в символ, но
        // модель их «дописывает». Проверяем только латиницу: в русском тексте латинское
        // слово — это почти всегда название, а склонения (которые ломали бы такую
        // проверку на кириллице) там не работают.
        // Смешанный алфавит внутри одного слова («Irina DrobysЛОysheva») — тот же класс
        // порчи, что символы чужих алфавитов, но внутри латинского названия, поэтому
        // предыдущая проверка его не видит. Точные вхождения из ФАКТОВ разрешаем:
        // название бренда действительно может быть смешанным.
        if ($mixed = $this->findMixedScriptWord($everything, $facts)) {
            return [
                'passed'   => false,
                'reasons'  => [sprintf('смешанный алфавит в слове: «%s»', $mixed)],
                'plainLen' => $plainLen,
                'faqCount' => count($faq),
                'overall'  => null,
            ];
        }

        if ($distorted = $this->findDistortedName($everything, $facts)) {
            return [
                'passed'   => false,
                'reasons'  => [sprintf('искажённое название: «%s» вместо «%s»', $distorted[0], $distorted[1])],
                'plainLen' => $plainLen,
                'faqCount' => count($faq),
                'overall'  => null,
            ];
        }

        foreach (self::CLICHES as $cliche) {
            if (mb_stripos($everything, $cliche) !== false) {
                return [
                    'passed'   => false,
                    'reasons'  => [sprintf('рекламный штамп: «%s»', $cliche)],
                    'plainLen' => $plainLen,
                    'faqCount' => count($faq),
                    'overall'  => null,
                ];
            }
        }

        foreach (self::JUNK_PATTERNS as $label => $re) {
            if (preg_match($re, $everything, $m) === 1) {
                return [
                    'passed'   => false,
                    'reasons'  => [sprintf('%s: «%s»', $label, mb_substr(trim($m[0]), 0, 40))],
                    'plainLen' => $plainLen,
                    'faqCount' => count($faq),
                    'overall'  => null,
                ];
            }
        }

        if (count($faq) < self::MIN_FAQ_PAIRS) {
            return $this->gateFail([sprintf('FAQ %d пар(ы) < %d', count($faq), self::MIN_FAQ_PAIRS)], $plainLen, count($faq));
        }

        $qa      = $this->articleQa->check($plain);
        $overall = $qa['metrics']['overall'] ?? null;
        $passed  = !$qa['checked'] || ($overall !== null && $overall >= self::MIN_QA_OVERALL);

        return [
            'passed'   => $passed,
            'reasons'  => $passed ? [] : [sprintf('overall %.1f < %.1f', $overall ?? 0.0, self::MIN_QA_OVERALL)],
            'plainLen' => $plainLen,
            'faqCount' => count($faq),
            'overall'  => $overall,
            'plain'    => $plain,
            'qa'       => $qa,
        ];
    }

    /** @param string[] $reasons */
    private function gateFail(array $reasons, int $plainLen, int $faqCount): array
    {
        return ['passed' => false, 'reasons' => $reasons, 'plainLen' => $plainLen, 'faqCount' => $faqCount, 'overall' => null, 'plain' => '', 'qa' => null];
    }
}
