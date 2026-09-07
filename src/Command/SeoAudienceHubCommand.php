<?php

namespace App\Command;

use App\Entity\Brand;
use App\Repository\BrandAudienceRepository;
use App\Repository\BrandRepository;
use App\Service\LlmService;
use App\Service\Seo\GeneratedTextGate;
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
 * SEO: наполняет BrandAudience::description (кураторский текст фасетной посадочной
 * /{_locale}/audience/{slug}) grounded-контентом из состава каталога — аналог
 * SeoStyleHubCommand для аудитории (Женщины/Мужчины/Дети/Унисекс).
 *
 * Зачем: неотработанный спрос (docs/geo_city_demand_2026_09.md §11) — «российские
 * бренды женской одежды» 2103 + «...для женщин» 1330, мужской 1042+675, детской 657
 * показов/мес, наших показов ноль — публичной страницы не существовало вообще.
 *
 * Факты — ТОЛЬКО из БД (число брендов аудитории, их title/anons, топ-5 городов), без
 * скрейпа. Поисковые фразы — из gsc_query_page/yandex_query_page по фактическому
 * URL страницы (страница новая — там пока пусто) плюс из gsc_query_stats/
 * yandex_query_stats по маске аудитории («женск», «для женщин» и т.п.). Проверки
 * контента — общий App\Service\Seo\GeneratedTextGate, тот же, что у городов и стилей.
 *
 * ⚠️ Шаблон (tailwind/audience.html.twig) выводит description БЕЗ |raw — текст
 * должен быть плоским, без HTML-тегов.
 *
 *   php bin/console app:seo:audience-hub --audience=female --dry-run
 *   php bin/console app:seo:audience-hub 4 --min-brands=20 --no-debug
 */
#[AsCommand(
    name: 'app:seo:audience-hub',
    description: 'SEO: контент хаба аудитории (description) из фактов каталога',
)]
class SeoAudienceHubCommand extends Command
{
    /** Русский корень темы для проверки дословных фраз: slug английский, он тут бесполезен. */
    private const TOPIC_ROOTS = [
        'female'  => 'женск',
        'male'    => 'мужск',
        'kids'    => 'детск',
        'unisex'  => 'унисекс',
    ];

    private const MAX_BRANDS_IN_FACTS = 20;   // сколько брендов перечислить в фактах для LLM
    private const MAX_ANONS_LEN       = 160;  // обрезка anons/description в фактах
    private const MAX_PHRASES         = 12;   // поисковых фраз в промпт

    // Плоский текст (см. LlmService::generateAudienceHub), тот же диапазон, что у стилей.
    private const MIN_DESCRIPTION_LEN = 600;
    private const MAX_DESCRIPTION_LEN = 1100;

    /** Тот же порог near-dup, что у стилей/городов (MAX_JACCARD). */
    private const MAX_JACCARD = 0.35;

    /**
     * Маски для поиска фраз в gsc_query_stats/yandex_query_stats. В отличие от
     * BackfillBrandAudienceCommand::RULES (там голый корень «детск» ложно ловит
     * риторику вроде «детские воспоминания» в свободной прозе бренда), здесь это
     * маска ПОИСКОВОГО ЗАПРОСА — там свободной прозы нет, риск многословных ложных
     * срабатываний намного ниже, а узкое правило только теряло бы реальные фразы
     * («детская одежда», «бренд детской одежды»).
     */
    private const AUDIENCE_ALIASES = [
        'female'  => ['женск', 'для женщин'],
        'male'    => ['мужск', 'для мужчин'],
        'kids'    => ['детск', 'для детей', 'подростк'],
        'unisex'  => ['унисекс'],
    ];

    private int $processed  = 0;
    private int $saved      = 0;
    private int $gateFailed = 0;
    private int $llmErrors  = 0;

    // Корпус для near-dup: существующие непустые description (заполняется один раз
    // в execute()) плюс аудитории, уже принятые в ЭТОМ прогоне — как в SeoStyleHubCommand.
    /** @var array<string,string> slug => description */
    private array $existingDescriptions = [];
    /** @var array<string,string> */
    private array $generatedDescriptions = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LlmService $llm,
        private readonly GeneratedTextGate $gate,
        private readonly BrandAudienceRepository $audienceRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, 'Максимум аудиторий за прогон', 4)
            ->addOption('audience', null, InputOption::VALUE_REQUIRED, 'Одна аудитория по slug')
            ->addOption('min-brands', null, InputOption::VALUE_REQUIRED, 'Минимум активных брендов в аудитории (тонкая страница — не индексируем)', 20)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Перезаписать уже заполненное description (QA-гейт --force не отключает)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Не сохранять, показать результат в консоли')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io           = new SymfonyStyle($input, $output);
        $limit        = max(1, (int) $input->getArgument('limit'));
        $audienceOpt  = $input->getOption('audience');
        $minBrands    = max(1, (int) $input->getOption('min-brands'));
        $force        = (bool) $input->getOption('force');
        $dryRun       = (bool) $input->getOption('dry-run');

        $io->title('SEO · контент хабов аудитории');
        if ($dryRun) {
            $io->note('dry-run — без сохранения');
        }

        foreach ($this->audienceRepo->findBy(['status' => Statuses::Active]) as $audience) {
            $desc = trim((string) $audience->getDescription());
            if ($desc !== '') {
                $this->existingDescriptions[$audience->getSlug()] = $desc;
            }
        }

        $slugs = $audienceOpt !== null
            ? [(string) $audienceOpt]
            : $this->selectAudiences($limit, $minBrands, $force);

        if ($slugs === []) {
            $io->success('Нет аудиторий-кандидатов (у всех подходящих уже есть description, либо брендов мало).');
            return Command::SUCCESS;
        }

        foreach ($slugs as $slug) {
            $this->processAudience($slug, $minBrands, $force, $dryRun, $io);
        }

        $io->newLine();
        $io->table(['Результат', 'Кол-во'], [
            ['Аудиторий обработано', $this->processed],
            ['Сохранено', $this->saved],
            ['Не прошло гейт', $this->gateFailed],
            ['Ошибок LLM', $this->llmErrors],
        ]);

        return Command::SUCCESS;
    }

    /**
     * Кандидаты: опубликованные аудитории с ≥minBrands активных брендов (те же гейты,
     * что у публичного листинга — excludeForeignOrigin, плюс отсечь niche_status='off'),
     * у которых ещё нет description (--force снимает условие), по убыванию числа брендов.
     *
     * @return string[] slugs
     */
    private function selectAudiences(int $limit, int $minBrands, bool $force): array
    {
        /** @var BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);
        $qb = $repo->createQueryBuilder('b')
            ->select('a.slug AS slug, COUNT(DISTINCT b.id) AS cnt')
            ->join('b.audiences', 'a')
            ->where('b.status = :status')
            ->andWhere('a.status = :status')
            ->andWhere("b.nicheStatus IS NULL OR b.nicheStatus != 'off'")
            ->setParameter('status', Statuses::Active)
            ->groupBy('a.id')
            ->having('cnt >= :minBrands')
            ->setParameter('minBrands', $minBrands)
            ->orderBy('cnt', 'DESC');
        $repo->excludeForeignOrigin($qb);
        $rows = $qb->getQuery()->getResult();

        $slugs = [];
        foreach ($rows as $row) {
            $slug = (string) $row['slug'];
            if (!$force && isset($this->existingDescriptions[$slug])) {
                continue;
            }
            $slugs[] = $slug;
            if (count($slugs) >= $limit) {
                break;
            }
        }

        return $slugs;
    }

    private function processAudience(string $slug, int $minBrands, bool $force, bool $dryRun, SymfonyStyle $io): void
    {
        $this->processed++;
        $io->section($slug);

        $audience = $this->audienceRepo->findOneBy(['slug' => $slug]);
        if ($audience === null || !$audience->isPublished()) {
            $io->text('  пропуск: аудитория не найдена или не опубликована');
            return;
        }

        $topicPhrase = trim((string) ($audience->getH1() ?: $audience->getTitle()));
        if ($topicPhrase === '') {
            $io->text('  пропуск: у аудитории не заполнено ни h1, ни title');
            return;
        }

        $existingDesc = trim((string) $audience->getDescription());
        if ($existingDesc !== '' && !$force) {
            $io->text('  пропуск: description уже есть (--force для перезаписи)');
            return;
        }

        /** @var BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);
        $brandsQb = $repo->createQueryBuilder('b')
            ->join('b.audiences', 'a')
            ->where('b.status = :status')
            ->andWhere('a.slug = :slug')
            // Ниша-гейт: страница пока показывает и off-niche, но в ФАКТЫ для LLM их
            // пускать нельзя (тот же приём, что в SeoStyleHubCommand::processStyle).
            ->andWhere("b.nicheStatus IS NULL OR b.nicheStatus != 'off'")
            // Для ФАКТОВ требуем ПОДТВЕРЖДЁННОЕ российское происхождение, а не просто
            // «не foreign», как в листингах — проза утверждает то, чего сетка карточек
            // не утверждает (см. историю с немецкой Ziener в SeoStyleHubCommand).
            ->andWhere("b.originStatus = 'ru'")
            ->setParameter('status', Statuses::Active)
            ->setParameter('slug', $slug)
            // Порядок для ФАКТОВ — по длине описания, а НЕ по алфавиту: у аудитории
            // сотни брендов, в факты попадают первые 20, и сортировка по title даёт
            // текст про начало алфавита, а не про представителей темы (тот же приём,
            // что в SeoStyleHubCommand::processStyle).
            ->orderBy('LENGTH(b.description)', 'DESC')
            ->addOrderBy('b.title', 'ASC');
        $repo->excludeForeignOrigin($brandsQb);
        $brands = $brandsQb->getQuery()->getResult();

        if (count($brands) < $minBrands) {
            $io->text(sprintf('  пропуск: %d активных брендов < %d (тонкая страница)', count($brands), $minBrands));
            return;
        }

        $facts   = $this->collectFacts((string) $audience->getTitle(), $brands, $repo, $slug);
        $phrases = $this->collectPhrases($slug);

        try {
            $result = $this->llm->generateAudienceHub((string) $audience->getTitle(), $topicPhrase, $facts, $phrases);
        } catch (\Throwable $e) {
            $io->warning('  LLM ошибка: ' . $e->getMessage());
            $this->llmErrors++;
            return;
        }

        $description = $result['description'];
        if ($description === null) {
            $io->warning('  LLM вернула пустой текст');
            $this->gateFailed++;
            return;
        }

        // Тот же приём, что в SeoStyleHubCommand: детерминированная вычитка орфографии
        // и починка искажённых названий брендов до гейта, названия — в protected.
        $protected  = array_map(static fn(Brand $b) => (string) $b->getTitle(), $brands);
        $spellFixes = 0;
        $description = $this->gate->spellFix($description, $protected, $spellFixes);
        if ($spellFixes > 0) {
            $io->text(sprintf('  орфография: исправлено %d', $spellFixes));
        }

        $nameFixes = 0;
        $description = $this->gate->fixDistortedNames($description, $facts, $nameFixes);
        if ($nameFixes > 0) {
            $io->text(sprintf('  названия брендов: исправлено %d', $nameFixes));
        }

        $gateResult = $this->checkGate($description, $phrases, $facts, $slug, $topicPhrase);
        $overallStr = $gateResult['overall'] !== null ? sprintf('%.1f', $gateResult['overall']) : '?';
        if (!$gateResult['passed']) {
            $io->warning(sprintf('  не прошло гейт (%d симв., overall %s): %s', $gateResult['len'], $overallStr, implode('; ', $gateResult['reasons'])));
            $this->gateFailed++;
            return;
        }
        $io->text(sprintf('  QA: overall %s, %d симв.', $overallStr, $gateResult['len']));
        $qa = $gateResult['qa'];
        if ($qa !== null && $qa['checked']) {
            $io->text(sprintf(
                '  QA детали: SB %s, HL %s%s',
                isset($qa['metrics']['spambrain']) ? sprintf('%.1f', $qa['metrics']['spambrain']) : '?',
                isset($qa['metrics']['human_likeness']) ? sprintf('%.1f', $qa['metrics']['human_likeness']) : '?',
                $qa['passed'] ? '' : ' — ниже пола описаний брендов: ' . implode('; ', $qa['reasons']),
            ));
        }

        $dup = $this->gate->checkNearDup($slug, [
            'description' => [$gateResult['plain'], $this->existingDescriptions, $this->generatedDescriptions, self::MAX_JACCARD],
        ]);
        $io->text(sprintf('  near-dup: %.2f (%s)', $dup['description']['score'], $dup['description']['key'] ?? '—'));
        if ($dup['failedField'] !== null) {
            $io->warning(sprintf('  near-dup: совпал с «%s» (Jaccard %.2f) — пропуск', $dup['description']['key'], $dup['description']['score']));
            $this->gateFailed++;
            return;
        }

        // Аудитория принята — попадает в пул сравнения для ОСТАЛЬНЫХ аудиторий этого же
        // прогона (независимо от --dry-run: near-dup — проверка контента, не БД).
        $this->generatedDescriptions[$slug] = $gateResult['plain'];

        if ($dryRun) {
            $io->text('  description: ' . $gateResult['plain']);
            return;
        }

        $audience->setDescription($gateResult['plain']);
        $this->em->persist($audience);
        $this->em->flush();
        $this->saved++;
        $io->text('  сохранено');
    }

    /**
     * Факты для промпта — только то, что реально есть в каталоге: число брендов,
     * до 20 брендов (title + обрезанный anons, а при пустом anons — начало
     * description), топ-5 городов с числом брендов.
     *
     * @param Brand[] $brands
     */
    private function collectFacts(string $audienceTitle, array $brands, BrandRepository $repo, string $slug): string
    {
        $lines = [sprintf('Активных брендов одежды в категории «%s»: %d', $audienceTitle, count($brands))];

        foreach (array_slice($brands, 0, self::MAX_BRANDS_IN_FACTS) as $b) {
            // Битые названия в факты не отдаём — см. тот же приём в SeoStyleHubCommand::collectFacts.
            if (preg_match('/[^\p{L}\p{N}\s]{3,}/u', (string) $b->getTitle()) === 1) {
                continue;
            }
            $detail = trim((string) $b->getAnons());
            if ($detail === '') {
                $detail = trim(strip_tags((string) $b->getDescription()));
            }
            $line = (string) $b->getTitle();
            if ($detail !== '') {
                $line .= ' — ' . mb_substr($detail, 0, self::MAX_ANONS_LEN);
            }
            $lines[] = $line;
        }

        $citiesQb = $repo->createQueryBuilder('b')
            ->select('b.city AS city, COUNT(DISTINCT b.id) AS cnt')
            ->join('b.audiences', 'a')
            ->where('b.status = :status')
            ->andWhere('a.slug = :slug')
            ->andWhere('b.city IS NOT NULL')
            ->andWhere("b.city != ''")
            ->andWhere("b.nicheStatus IS NULL OR b.nicheStatus != 'off'")
            ->setParameter('status', Statuses::Active)
            ->setParameter('slug', $slug)
            ->groupBy('b.city')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults(5);
        $repo->excludeForeignOrigin($citiesQb);
        $cities = $citiesQb->getQuery()->getResult();
        if ($cities !== []) {
            $lines[] = 'Топ городов: ' . implode(', ', array_map(
                static fn(array $c) => sprintf('%s (%d)', $c['city'], $c['cnt']),
                $cities,
            ));
        }

        // Артефакты экранирования в исходных данных чистим на входе — тот же приём,
        // что в SeoStyleHubCommand::collectFacts.
        return preg_replace(["/'{2,}/u", '/\\\\{2,}/u'], ["'", '\\'], implode("\n", $lines)) ?? implode("\n", $lines);
    }

    /**
     * Поисковые фразы по этой аудитории: сначала из gsc_query_page/yandex_query_page
     * по фактическому URL страницы (страница новая — сейчас пусто, но код готов на
     * будущее), затем из gsc_query_stats/yandex_query_stats по маске аудитории
     * (AUDIENCE_ALIASES). Навигационные фразы (с названием бренда внутри) отсекаем —
     * тот же приём, что в SeoCityHubCommand/SeoStyleHubCommand::collectPhrases.
     *
     * @return string[]
     */
    private function collectPhrases(string $slug): array
    {
        $conn = $this->em->getConnection();
        $rows = [];

        $like = '%/audience/' . $slug . '%';
        try {
            $rows = array_merge($rows, $conn->fetchAllAssociative(
                'SELECT query AS phrase, SUM(impressions) AS demand FROM gsc_query_page WHERE page_url LIKE ? GROUP BY query',
                [$like],
            ));
        } catch (\Throwable) {
            // таблицы нет / крон синка ещё не отработал — просто без GSC-фраз
        }
        try {
            $rows = array_merge($rows, $conn->fetchAllAssociative(
                'SELECT query AS phrase, SUM(impressions) AS demand FROM yandex_query_page WHERE page_url LIKE ? GROUP BY query',
                [$like],
            ));
        } catch (\Throwable) {
        }

        $aliases = self::AUDIENCE_ALIASES[$slug] ?? [];
        if ($aliases !== []) {
            $like = array_map(static fn(string $a) => '%' . mb_strtolower($a) . '%', $aliases);

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
        }

        usort($rows, static fn(array $a, array $b) => (int) $b['demand'] <=> (int) $a['demand']);

        $brandTitles = array_values(array_filter(
            array_map(
                static fn(array $r) => mb_strtolower(trim((string) $r['title'])),
                $conn->fetchAllAssociative(
                    "SELECT DISTINCT title FROM brand WHERE status = 'active' AND CHAR_LENGTH(title) >= 4",
                ),
            ),
            static fn(string $t) => $t !== '',
        ));

        $seen    = [];
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
     * Гейт качества текста аудитории: длина плоского текста, отсутствие HTML-тегов
     * (шаблон рендерит description БЕЗ |raw), дословные поисковые фразы, искажения/
     * штампы/мусор, QA-балл — все проверки общие с городами/стилями через
     * GeneratedTextGate, порядок и пороги (длина, FAQ здесь нет) — свои.
     *
     * @param string[] $phrases фразы, отданные модели: дословных вхождений быть не должно
     * @param string   $facts   факты, отданные модели: латиница текста должна совпадать с ними
     *
     * @return array{passed: bool, reasons: string[], len: int, overall: ?float, plain: string, qa: ?array}
     */
    private function checkGate(string $description, array $phrases, string $facts, string $slug, string $topicPhrase): array
    {
        $stored = trim($description);

        if (strip_tags($stored) !== $stored) {
            return $this->gateFail(['текст содержит HTML-теги'], mb_strlen($stored));
        }

        $len = mb_strlen($stored);
        if ($lengthReason = $this->gate->checkLength($len, self::MIN_DESCRIPTION_LEN, self::MAX_DESCRIPTION_LEN)) {
            return $this->gateFail([$lengthReason], $len);
        }

        $everything    = preg_replace('/\s+/u', ' ', $stored) ?? $stored;
        $haystackLower = mb_strtolower($everything);

        // Аналог стилей: фраза проверяется на дословное вхождение, только если
        // содержит slug ИЛИ topicPhrase (h1) отдельным словом — иначе живая речь
        // ложно бракуется. Короткие фразы (≤2 слов) не проверяем вовсе — топ-запрос
        // темы часто и есть единственная живая формулировка (тот же приём, что
        // «стиль архив» у SeoStyleHubCommand).
        $checkable = array_values(array_filter(
            $phrases,
            static fn(string $p) => count(preg_split('/\s+/u', trim($p)) ?: []) >= 3,
        ));
        // Анкор — русский корень аудитории, а НЕ slug и не полный h1. Слаги здесь
        // английские ('female'/'male'/'kids'), а h1 — целая фраза; ни то, ни другое
        // практически никогда не совпадает с живой русской речью, и проверка не
        // срабатывала вовсе: в мужской текст прошла склейка «бренды мужской одежды спб»
        // — запросный порядок слов с «спб» без предлога.
        $verbatim = null;
        foreach ([self::TOPIC_ROOTS[$slug] ?? null, mb_strtolower($slug), mb_strtolower($topicPhrase)] as $anchor) {
            if ($anchor === null) {
                continue;
            }
            $verbatim ??= $this->gate->findVerbatimPhrase($haystackLower, $checkable, $anchor);
        }
        if ($verbatim !== null) {
            return $this->gateFail([sprintf('дословная поисковая фраза в тексте: «%s»', $verbatim)], $len);
        }

        if ($mixed = $this->gate->findMixedScriptWord($everything, $facts)) {
            return $this->gateFail([sprintf('смешанный алфавит в слове: «%s»', $mixed)], $len);
        }

        if ($distorted = $this->gate->findDistortedName($everything, $facts)) {
            return $this->gateFail([sprintf('искажённое название: «%s» вместо «%s»', $distorted[0], $distorted[1])], $len);
        }

        if ($cliche = $this->gate->findCliche($everything)) {
            return $this->gateFail([sprintf('рекламный штамп: «%s»', $cliche)], $len);
        }

        if ($junk = $this->gate->findJunkPattern($everything)) {
            return $this->gateFail([sprintf('%s: «%s»', $junk[0], $junk[1])], $len);
        }

        $qa = $this->gate->qaOverall($stored);

        return [
            'passed'  => $qa['passed'],
            'reasons' => $qa['passed'] ? [] : [sprintf('overall %.1f < %.1f', $qa['overall'] ?? 0.0, GeneratedTextGate::MIN_QA_OVERALL)],
            'len'     => $len,
            'overall' => $qa['overall'],
            'plain'   => $stored,
            'qa'      => $qa['qa'],
        ];
    }

    /** @param string[] $reasons */
    private function gateFail(array $reasons, int $len): array
    {
        return ['passed' => false, 'reasons' => $reasons, 'len' => $len, 'overall' => null, 'plain' => '', 'qa' => null];
    }
}
