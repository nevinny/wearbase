<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandStyle;
use App\Repository\BrandRepository;
use App\Repository\BrandStyleRepository;
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
 * SEO: наполняет BrandStyle::description (кураторский текст стилевой посадочной
 * /{_locale}/style/{slug}) grounded-контентом из состава каталога — аналог
 * SeoCityHubCommand для стилей. У 27 из 29 стилей description пуст, и страница
 * ранжируется вообще без уникального контента, хотя спрос до неё доходит
 * («авангард бренд» поз. 5.3 в Google, «стиль архив» 212 показов в Яндексе).
 *
 * Факты — ТОЛЬКО из БД (число брендов стиля, их title/anons, топ-5 городов), без
 * скрейпа. Поисковые фразы — из gsc_query_page/yandex_query_page по фактическому
 * URL страницы (проще городов: URL уже содержит slug, алиасы не нужны). Проверки
 * контента (орфография, искажённые названия, штампы/мусор, QA-балл, near-dup) —
 * общий App\Service\Seo\GeneratedTextGate, тот же, что использует SeoCityHubCommand.
 *
 * ⚠️ Шаблон (tailwind/style.html.twig:64) выводит description БЕЗ |raw — текст
 * должен быть плоским, без HTML-тегов (в отличие от intro городов).
 *
 *   php bin/console app:seo:style-hub --style=avantgarde --dry-run
 *   php bin/console app:seo:style-hub 5 --min-brands=20 --no-debug
 */
#[AsCommand(
    name: 'app:seo:style-hub',
    description: 'SEO: контент стилевого хаба (description) из фактов каталога',
)]
class SeoStyleHubCommand extends Command
{
    private const MAX_BRANDS_IN_FACTS = 20;   // сколько брендов перечислить в фактах для LLM
    private const MAX_ANONS_LEN       = 160;  // обрезка anons/description в фактах
    private const MAX_PHRASES         = 12;   // поисковых фраз в промпт

    // Плоский текст (см. LlmService::generateStyleHub). У стилей брендов на порядок
    // больше, чем у городов, — фактов достаточно даже без длинного текста; диапазон
    // уже, чем у HTML-intro городов (450–2500), потому что тут 2–3 абзаца без разметки.
    private const MIN_DESCRIPTION_LEN = 600;
    private const MAX_DESCRIPTION_LEN = 1100;

    /**
     * Тот же порог, что MAX_JACCARD у intro городов (SeoCityHubCommand): своей
     * истории near-dup у стилей ещё нет (первый прогон), а description играет ту же
     * роль единственного «тела текста» страницы, что intro у города.
     */
    private const MAX_JACCARD = 0.35;

    private int $processed  = 0;
    private int $saved      = 0;
    private int $gateFailed = 0;
    private int $llmErrors  = 0;

    // Корпус для near-dup: существующие непустые description (заполняется один раз
    // в execute()) плюс стили, уже принятые в ЭТОМ прогоне — как в SeoCityHubCommand.
    /** @var array<string,string> slug => description */
    private array $existingDescriptions = [];
    /** @var array<string,string> */
    private array $generatedDescriptions = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LlmService $llm,
        private readonly GeneratedTextGate $gate,
        private readonly BrandStyleRepository $styleRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, 'Максимум стилей за прогон', 5)
            ->addOption('style', null, InputOption::VALUE_REQUIRED, 'Один стиль по slug')
            ->addOption('min-brands', null, InputOption::VALUE_REQUIRED, 'Минимум активных брендов в стиле (тонкая страница — не индексируем)', 20)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Перезаписать уже заполненное description (QA-гейт --force не отключает)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Не сохранять, показать результат в консоли')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $limit     = max(1, (int) $input->getArgument('limit'));
        $styleOpt  = $input->getOption('style');
        $minBrands = max(1, (int) $input->getOption('min-brands'));
        $force     = (bool) $input->getOption('force');
        $dryRun    = (bool) $input->getOption('dry-run');

        $io->title('SEO · контент стилевых хабов');
        if ($dryRun) {
            $io->note('dry-run — без сохранения');
        }

        foreach ($this->styleRepo->findBy(['status' => Statuses::Active]) as $style) {
            $desc = trim((string) $style->getDescription());
            if ($desc !== '') {
                $this->existingDescriptions[$style->getSlug()] = $desc;
            }
        }

        $slugs = $styleOpt !== null
            ? [(string) $styleOpt]
            : $this->selectStyles($limit, $minBrands, $force);

        if ($slugs === []) {
            $io->success('Нет стилей-кандидатов (у всех подходящих уже есть description, либо брендов мало).');
            return Command::SUCCESS;
        }

        foreach ($slugs as $slug) {
            $this->processStyle($slug, $minBrands, $force, $dryRun, $io);
        }

        $io->newLine();
        $io->table(['Результат', 'Кол-во'], [
            ['Стилей обработано', $this->processed],
            ['Сохранено', $this->saved],
            ['Не прошло гейт', $this->gateFailed],
            ['Ошибок LLM', $this->llmErrors],
        ]);

        return Command::SUCCESS;
    }

    /**
     * Кандидаты: опубликованные стили с ≥minBrands активных брендов (те же гейты, что
     * у публичного листинга — excludeForeignOrigin, плюс отсечь niche_status='off',
     * которых страница пока показывает, но в факты для LLM пускать нельзя), у которых
     * ещё нет description (--force снимает условие), по убыванию числа брендов.
     *
     * @return string[] slugs
     */
    private function selectStyles(int $limit, int $minBrands, bool $force): array
    {
        /** @var BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);
        $qb = $repo->createQueryBuilder('b')
            ->select('s.slug AS slug, COUNT(DISTINCT b.id) AS cnt')
            ->join('b.styles', 's')
            ->where('b.status = :status')
            ->andWhere('s.status = :status')
            ->andWhere("b.nicheStatus IS NULL OR b.nicheStatus != 'off'")
            ->setParameter('status', Statuses::Active)
            ->groupBy('s.id')
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

    private function processStyle(string $slug, int $minBrands, bool $force, bool $dryRun, SymfonyStyle $io): void
    {
        $this->processed++;
        $io->section($slug);

        $style = $this->styleRepo->findOneBy(['slug' => $slug]);
        if ($style === null || !$style->isPublished()) {
            $io->text('  пропуск: стиль не найден или не опубликован');
            return;
        }

        $existingDesc = trim((string) $style->getDescription());
        if ($existingDesc !== '' && !$force) {
            $io->text('  пропуск: description уже есть (--force для перезаписи)');
            return;
        }

        /** @var BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);
        $brandsQb = $repo->createQueryBuilder('b')
            ->join('b.styles', 's')
            ->where('b.status = :status')
            ->andWhere('s.slug = :slug')
            // Ниша-гейт: страница пока показывает и off-niche, но в ФАКТЫ для LLM их
            // пускать нельзя (см. тот же приём в SeoCityHubCommand::processCity).
            ->andWhere("b.nicheStatus IS NULL OR b.nicheStatus != 'off'")
            // Для ФАКТОВ требуем ПОДТВЕРЖДЁННОЕ российское происхождение, а не просто
            // «не foreign», как в листингах. Причина: проза утверждает то, чего сетка
            // карточек не утверждает. С мягким фильтром в текст про «российские бренды
            // спортивной одежды» попал Ziener — немецкая семейная компания с 80-летней
            // историей, у которой origin_status стоял 'unknown'. У стилей таких брендов
            // треть (sport 119 из 367, streetwear 137 из 473), материала хватает и без них.
            ->andWhere("b.originStatus = 'ru'")
            ->setParameter('status', Statuses::Active)
            ->setParameter('slug', $slug)
            // Порядок для ФАКТОВ — по длине описания, а НЕ по алфавиту. У стиля сотни
            // брендов, в факты попадают первые 20, и при сортировке по title текст
            // получался про начало алфавита: «A.Karina, Adam Saint, Alberto.A, 2211GATE»
            // — не представители стиля, а случайная выборка. По длине описания наверх
            // всплывают бренды с самым богатым фактическим материалом, из которого
            // и строится grounded-текст (тот же приём в findListicleCompetitors).
            ->orderBy('LENGTH(b.description)', 'DESC')
            ->addOrderBy('b.title', 'ASC');
        $repo->excludeForeignOrigin($brandsQb);
        $brands = $brandsQb->getQuery()->getResult();

        if (count($brands) < $minBrands) {
            $io->text(sprintf('  пропуск: %d активных брендов < %d (тонкая страница)', count($brands), $minBrands));
            return;
        }

        $facts   = $this->collectFacts((string) $style->getTitle(), $brands, $repo, $slug);
        $phrases = $this->collectPhrases($slug);

        try {
            $result = $this->llm->generateStyleHub((string) $style->getTitle(), $facts, $phrases);
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

        // Тот же приём, что в SeoCityHubCommand: детерминированная вычитка орфографии
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

        $gateResult = $this->checkGate($description, $phrases, $facts, $style, $slug);
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

        // Стиль принят — попадает в пул сравнения для ОСТАЛЬНЫХ стилей этого же
        // прогона (независимо от --dry-run: near-dup — проверка контента, не БД).
        $this->generatedDescriptions[$slug] = $gateResult['plain'];

        if ($dryRun) {
            $io->text('  description: ' . $gateResult['plain']);
            return;
        }

        $style->setDescription($gateResult['plain']);
        $this->em->persist($style);
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
    private function collectFacts(string $styleTitle, array $brands, BrandRepository $repo, string $slug): string
    {
        $lines = [sprintf('Активных брендов одежды в стиле «%s»: %d', $styleTitle, count($brands))];

        foreach (array_slice($brands, 0, self::MAX_BRANDS_IN_FACTS) as $b) {
            // Битые названия («YUGE ЮДЖ *Y-----W )))») в факты не отдаём — см. тот же
            // приём в SeoCityHubCommand::collectFacts.
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
            ->join('b.styles', 's')
            ->where('b.status = :status')
            ->andWhere('s.slug = :slug')
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

        // Артефакты экранирования в исходных данных («Master Of Chillin'''») чистим на
        // входе — тот же приём, что в SeoCityHubCommand::collectFacts.
        return preg_replace(["/'{2,}/u", '/\\\\{2,}/u'], ["'", '\\'], implode("\n", $lines)) ?? implode("\n", $lines);
    }

    /**
     * Поисковые фразы по URL этой стилевой страницы из gsc_query_page/yandex_query_page
     * (оконные снимки «запрос×URL» — проще городов: URL уже содержит slug, алиасы не
     * нужны), топ-12 по показам, дедуп. Навигационные фразы (с названием бренда внутри)
     * отсекаем — тот же приём, что в SeoCityHubCommand::collectPhrases.
     *
     * @return string[]
     */
    private function collectPhrases(string $slug): array
    {
        $conn = $this->em->getConnection();
        $like = '%/style/' . $slug . '%';

        $rows = [];
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
     * Гейт качества стилевого текста: длина плоского текста, отсутствие HTML-тегов
     * (шаблон рендерит description БЕЗ |raw), дословные поисковые фразы, искажения/
     * штампы/мусор, QA-балл — все проверки общие с городами через GeneratedTextGate,
     * порядок и пороги (длина, FAQ здесь нет) — свои.
     *
     * @param string[] $phrases фразы, отданные модели: дословных вхождений быть не должно
     * @param string   $facts   факты, отданные модели: латиница текста должна совпадать с ними
     *
     * @return array{passed: bool, reasons: string[], len: int, overall: ?float, plain: string, qa: ?array}
     */
    private function checkGate(string $description, array $phrases, string $facts, BrandStyle $style, string $slug): array
    {
        $stored = trim($description);

        if (strip_tags($stored) !== $stored) {
            return $this->gateFail(['текст содержит HTML-теги'], mb_strlen($stored));
        }

        $len = mb_strlen($stored);
        if ($lengthReason = $this->gate->checkLength($len, self::MIN_DESCRIPTION_LEN, self::MAX_DESCRIPTION_LEN)) {
            return $this->gateFail([$lengthReason], $len);
        }

        // Мусорные/штамповые проверки — по тексту с нормализованными пробелами
        // (переносы абзацев не мешают регуляркам), но с сохранением регистра.
        $everything    = preg_replace('/\s+/u', ' ', $stored) ?? $stored;
        $haystackLower = mb_strtolower($everything);

        // Аналог «города в именительном» у SeoCityHubCommand: фраза проверяется на
        // дословное вхождение, только если содержит slug ИЛИ title стиля отдельным
        // словом — иначе живая речь («спортивные бренды одежды») ложно бракуется.
        //
        // Дополнительно отбрасываем короткие фразы (≤2 слов): у стиля его название —
        // существительное, и естественный способ назвать тему совпадает с запросом.
        // «стиль архив» (212 показов в Яндексе) — это и запрос, и единственная живая
        // формулировка; текст про стиль «Архив» физически не может её обойти, и стиль
        // не проходил гейт вообще. Признак настоящего переспама — склейка из трёх и
        // более слов без предлогов («архив стиль одежды»), её и проверяем.
        $checkable = array_values(array_filter(
            $phrases,
            static fn(string $p) => count(preg_split('/\s+/u', trim($p)) ?: []) >= 3,
        ));
        $verbatim = $this->gate->findVerbatimPhrase($haystackLower, $checkable, mb_strtolower($slug))
            ?? $this->gate->findVerbatimPhrase($haystackLower, $checkable, mb_strtolower((string) $style->getTitle()));
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
