<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\CityHub;
use App\Repository\BrandRepository;
use App\Repository\CityHubRepository;
use App\Service\ArticleQaService;
use App\Service\CitySlugger;
use App\Service\LlmService;
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
        private readonly NearDuplicateDetector $nearDup,
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

        $gate = $this->checkGate($result);
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
            $limit = $field === 'meta_description' ? self::MAX_JACCARD_META : self::MAX_JACCARD;
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

        return implode("\n", $lines);
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
        $brandTitles = [];
        foreach ($brands as $b) {
            $t = mb_strtolower(trim((string) $b->getTitle()));
            if (mb_strlen($t) >= 4) {
                $brandTitles[] = $t;
            }
        }

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
    private function checkGate(array $result): array
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
