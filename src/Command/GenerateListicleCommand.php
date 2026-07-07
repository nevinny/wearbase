<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandKeyword;
use App\Entity\BrandStyle;
use App\Repository\BrandKeywordRepository;
use App\Repository\BrandRepository;
use App\Service\BrandRagService;
use App\Service\ContentValidator;
use App\Service\LlmService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SEO Boost / GEO (MVP): генерирует листикл «ТОП-N лучших брендов в нише», где
 * целевой бренд стоит на 1-м месте, а остальные места — реальные конкуренты той
 * же категории из каталога. Каждый бренд описывается СТРОГО из своих фактов
 * (описание + RAG-корпус), без выдумок. На выходе — markdown-статья + JSON-LD
 * (Article + ItemList) для внешней публикации (vc.ru, dtf.ru, Пикабу и т.д.).
 *
 * Это внутренняя альтернатива платной услуге ContentMagic «SEO Boost».
 * Статья НЕ публикуется на wearbase.ru (self-домен) — её размещают на внешних
 * площадках; ссылки в ItemList ведут на страницы брендов в каталоге (бэклинки).
 *
 * --variants N: пакет из N статей с ротацией автора-персоны, площадки/тона и
 * порядка конкурентов (целевой всегда №1) — чтобы тексты не были близнецами,
 * как и обещает КП «20 уникальных статей». FAQ-блок (FAQPage JSON-LD) строится
 * из Wordstat-фраз целевого бренда через BrandRagService + generateBrandFaq.
 *
 *   php bin/console app:seo:listicle 42                      # ниша — из стиля бренда
 *   php bin/console app:seo:listicle 42 ulichnyy-stil        # ниша — стиль по slug
 *   php bin/console app:seo:listicle 42 auto --platform=pikabu --persona=2 --top=5
 *   php bin/console app:seo:listicle 42 auto --variants=5 --out=var/seo  # пакет из 5
 *   php bin/console app:seo:listicle 42 auto --no-faq --out=var/seo      # без FAQ
 */
#[AsCommand(
    name: 'app:seo:listicle',
    description: 'SEO Boost: статья-рейтинг «ТОП-N в нише» с целевым брендом №1 (grounded)',
)]
class GenerateListicleCommand extends Command
{
    private const SITE_BASE = 'https://wearbase.ru';
    private const MAX_FACTS_PER_BRAND = 2500; // символов фактов на бренд в промпте
    private const MIN_BODY_WORDS = 700;       // gate-floor (ловит обрезку); цель промпта — 1300–2000.
                                              // 700, а не 900: ячейки из 3 брендов дают короче, но валидно.
    private const MAX_INTEXT_LINKS = 4;       // in-text ссылок на каталог (с UTM), как у конкурента (3–4)
    private const MAX_GEN_ATTEMPTS = 3;       // self-heal: попыток генерации с точечной правкой по gate

    /** Авторы-персоны (разный голос → 20 статей не близнецы). Индекс — опция --persona. */
    private const PERSONAS = [
        'независимый fashion-стилист из Москвы',
        'маркетолог в fashion-ритейле',
        'автор блога про осознанное потребление и российские бренды',
        'журналист, пишущий о моде и локальных дизайнерах',
        'предприниматель в e-commerce одежды',
        'покупатель-энтузиаст, который делится личным опытом',
    ];

    /** Тон под площадку (опция --platform). */
    private const PLATFORM_TONES = [
        'vc'     => 'экспертный аналитический обзор рынка, по-деловому, со структурой',
        'dtf'    => 'личный тест-эксперимент, неформально, от первого лица',
        'pikabu' => 'личный опыт простым разговорным языком (UGC), от первого лица',
        'press'  => 'официальный пресс-релиз, нейтрально и сдержанно',
        'blog'   => 'личный блог, рефлексивно и доверительно',
        'dzen'   => 'статья для Яндекс.Дзена: информативно и экспертно, но живым языком, с пользой и вовлечением читателя',
        'site'   => 'редакционная статья каталога WEARBASE для собственного блога: информативно, по-доброму экспертно, без рекламного давления',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LlmService             $llm,
        private readonly BrandRagService        $rag,
        private readonly ContentValidator       $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('brand', InputArgument::REQUIRED, 'ID целевого бренда (место №1)')
            ->addArgument('niche', InputArgument::OPTIONAL, 'Slug стиля-ниши (или auto — из стиля бренда)', 'auto')
            ->addOption('city',     null, InputOption::VALUE_REQUIRED, 'Гео-срез: конкуренты только из этого города (для «ТОП {стиль} {город}»)')
            ->addOption('top',      null, InputOption::VALUE_REQUIRED, 'Сколько мест в рейтинге (2..10)', '5')
            ->addOption('platform', null, InputOption::VALUE_REQUIRED, 'Площадка/тон: ' . implode('|', array_keys(self::PLATFORM_TONES)), 'vc')
            ->addOption('persona',  null, InputOption::VALUE_REQUIRED, 'Индекс автора-персоны (0..' . (count(self::PERSONAS) - 1) . ')', '0')
            ->addOption('variants', null, InputOption::VALUE_REQUIRED, 'Сколько статей сгенерить (ротация персон/площадок/порядка)', '1')
            ->addOption('no-faq',   null, InputOption::VALUE_NONE,     'Не добавлять FAQ-блок и FAQPage JSON-LD')
            ->addOption('force',    null, InputOption::VALUE_NONE,     'Сохранять даже при провале quality-gate (с предупреждением)')
            ->addOption('out',      null, InputOption::VALUE_REQUIRED, 'Папка для сохранения .md (- = вывод в консоль)', 'var/seo')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $brandId    = (int) $input->getArgument('brand');
        $niche      = (string) $input->getArgument('niche');
        $top        = max(2, min(10, (int) $input->getOption('top')));
        $platform   = (string) $input->getOption('platform');
        $personaIdx = (int) $input->getOption('persona');
        $variants   = max(1, (int) $input->getOption('variants'));
        $withFaq    = !$input->getOption('no-faq');
        $force      = (bool) $input->getOption('force');
        $outDir     = (string) $input->getOption('out');
        $city       = $input->getOption('city');

        if (!isset(self::PLATFORM_TONES[$platform])) {
            $io->error("Неизвестная площадка «{$platform}». Доступно: " . implode(', ', array_keys(self::PLATFORM_TONES)));
            return Command::FAILURE;
        }

        /** @var Brand|null $target */
        $target = $this->em->find(Brand::class, $brandId);
        if (!$target || !$target->getTitle()) {
            $io->error("Целевой бренд ID {$brandId} не найден (или без названия).");
            return Command::FAILURE;
        }

        $style = $this->resolveStyle($target, $niche, $io);
        if ($style === null) {
            return Command::FAILURE;
        }

        /** @var BrandRepository $brandRepo */
        $brandRepo   = $this->em->getRepository(Brand::class);
        $competitors = $brandRepo->findListicleCompetitors((int) $style->getId(), $brandId, $top - 1, $city, $target->getTitle());

        if ($competitors === []) {
            $io->error(sprintf(
                'В нише «%s»%s нет конкурентов с описанием, кроме целевого бренда. Выберите другую нишу/город.',
                $style->getTitle(),
                $city ? " (город «{$city}»)" : '',
            ));
            return Command::FAILURE;
        }

        // city×style: заголовок включает город. nicheTitle НЕ содержит «брендов» —
        // его подставляет шаблон title («ТОП-N брендов {nicheTitle}»), иначе дублируется.
        $nicheTitle = $city
            ? sprintf('%s — %s', $style->getTitle(), mb_convert_case(trim((string) $city), MB_CASE_TITLE, 'UTF-8'))
            : (string) $style->getTitle();

        $io->title('SEO Boost · листикл «ТОП-N в нише»');
        $io->definitionList(
            ['Ниша'     => $nicheTitle],
            ['Город'    => $city ?: '— (все)'],
            ['Целевой'  => $target->getTitle()],
            ['Мест'     => 1 + count($competitors)],
            ['Вариантов' => $variants],
            ['FAQ'      => $withFaq ? 'да' : 'нет'],
        );

        // --- Подготовка фактов ОДИН раз (RAG-вызовы дорогие) ---
        $io->section('Сбор фактов (описание + RAG)');
        $targetFacts = $this->collectFacts($target);
        $io->text(sprintf('  · %s — %d симв. фактов', $target->getTitle(), mb_strlen($targetFacts)));
        $compEntries = [];
        foreach ($competitors as $c) {
            $facts = $this->collectFacts($c);
            $compEntries[] = ['brand' => $c, 'llm' => ['name' => (string) $c->getTitle(), 'city' => $c->getCity(), 'facts' => $facts]];
            $io->text(sprintf('  · %s — %d симв. фактов', $c->getTitle(), mb_strlen($facts)));
        }

        $targetLlm = ['name' => (string) $target->getTitle(), 'city' => $target->getCity(), 'facts' => $targetFacts];
        $keywords  = $this->targetKeywords($target);

        // FAQ целевого бренда — один раз на все варианты (факты не зависят от площадки).
        $faq = [];
        if ($withFaq) {
            $io->section('FAQ целевого бренда (Wordstat → grounded)');
            $faq = $this->buildFaq($target, $targetFacts);
            $io->text($faq === [] ? '  нет подходящих фраз/фактов → FAQ пропущен' : sprintf('  %d Q/A пар', count($faq)));
        }

        // --- Генерация вариантов ---
        $platformKeys = array_keys(self::PLATFORM_TONES);
        $startPlat    = array_search($platform, $platformKeys, true) ?: 0;
        $compCount    = count($compEntries);
        $ok = 0;
        $rejected = 0;

        for ($i = 0; $i < $variants; $i++) {
            $vPlatform = $variants > 1 ? $platformKeys[($startPlat + $i) % count($platformKeys)] : $platform;
            $vPersona  = self::PERSONAS[($personaIdx + $i) % count(self::PERSONAS)];
            $vTone     = self::PLATFORM_TONES[$vPlatform];

            // Ротация порядка конкурентов (целевой всегда №1) → разная структура статьи.
            $shift   = $compCount > 0 ? $i % $compCount : 0;
            $rotComp = array_merge(array_slice($compEntries, $shift), array_slice($compEntries, 0, $shift));

            $orderedBrands = array_merge([$target], array_map(static fn($e) => $e['brand'], $rotComp));
            $llmBrands     = array_merge([$targetLlm], array_map(static fn($e) => $e['llm'], $rotComp));

            $io->section(sprintf('Вариант %d/%d · %s · %s', $i + 1, $variants, $vPlatform, $vPersona));

            // Self-heal: до MAX_GEN_ATTEMPTS попыток; на провал гейта чиним ИМЕННО findings
            // (не ре-ролл с нуля), температура снижается 0.7→0.6→0.5. Корректуру (applyProofread)
            // делаем один раз — на принятом черновике (она чинит опечатки, gate-критерии не ломает).
            $temps = [0.7, 0.6, 0.5];
            $fixHint = null;
            $body = null;
            $issues = ['пусто'];
            for ($att = 0; $att < self::MAX_GEN_ATTEMPTS; $att++) {
                try {
                    $raw = $this->llm->generateListicle($nicheTitle, $llmBrands, $vPersona, $vTone, $keywords, $fixHint, $temps[$att] ?? 0.5, noTables: $vPlatform === 'dzen');
                } catch (\Throwable $e) {
                    // LLM-блип (gemma под майнингом перезапускается) — не рушим батч,
                    // ждём и ретраим: транзиентный сбой переживём, не теряем остаток прогона.
                    $issues = ['LLM ошибка: ' . mb_substr($e->getMessage(), 0, 80)];
                    $io->text(sprintf('  попытка %d/%d → LLM недоступна, пауза 15с…', $att + 1, self::MAX_GEN_ATTEMPTS));
                    sleep(15);
                    continue;
                }
                if (trim($raw) === '') {
                    $issues = ['LLM вернула пусто'];
                    continue;
                }
                $raw = $this->softenCliches($raw);
                $issues = $this->qualityGate($raw, $orderedBrands, $keywords);
                $body = $raw;
                if ($issues === []) {
                    break;
                }
                $fixHint = implode('; ', $issues);
                $io->text(sprintf('  попытка %d/%d → gate: %s', $att + 1, self::MAX_GEN_ATTEMPTS, $fixHint));
            }

            if ($body === null || ($issues !== [] && !$force)) {
                $io->warning('  Отбраковано после ' . self::MAX_GEN_ATTEMPTS . ' попыток: ' . implode('; ', $issues));
                $rejected++;
                continue;
            }
            if ($issues !== [] && $force) {
                $io->note('  quality-gate (проигнорирован --force): ' . implode('; ', $issues));
            }

            // Корректура — один раз на принятом черновике (+ повторный softenCliches на случай,
            // если корректор вернул «уникальн»).
            $body = $this->softenCliches($this->applyProofread($body, $io));

            $title    = sprintf('ТОП-%d брендов %s: рейтинг %s', count($orderedBrands), $nicheTitle, date('Y'));
            $campaign = sprintf('%s-%s-%s', $style->getSlug(), $target->getSlug(), $vPlatform);

            // P1-методология: in-text ссылки на каталог с UTM (ссылка в 1-м абзаце —
            // целевой бренд идёт первым), оглавление, CTA-блок, UTM в JSON-LD.
            $linkedBody = $this->linkifyBody($body, $orderedBrands, $vPlatform, $campaign);
            $toc        = $this->buildToc($linkedBody);
            $cta        = $this->buildCta($target, $vPlatform, $campaign);
            $faqMd      = $this->buildFaqMarkdown($faq);
            // Article+author даёт шаблон. В контент цементируем снимок рейтинга (ItemList+FAQ),
            // без узла Article. buildJsonLd отдаёт нужный вариант по платформе.
            $jsonLd     = $this->buildJsonLd($title, $orderedBrands, $vPersona, $faq, $vPlatform, $campaign);
            $document   = $this->renderDocument($title, $vPersona, $vPlatform, $toc, $linkedBody, $faqMd, $cta, $jsonLd);

            if ($outDir === '-') {
                $output->writeln($document);
            } else {
                $path = $this->saveDocument($outDir, $style, $target, $vPlatform, $personaIdx + $i, $document, $io);
                $io->success("Сохранено: {$path}");
            }
            $ok++;
        }

        $io->newLine();
        $io->table(['Результат', 'Кол-во'], [
            ['Готово',                $ok],
            ['Отбраковано/пусто',     $rejected],
        ]);

        return $ok > 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Quality-gate готовой статьи: возвращает список проблем (пусто = прошёл).
     * Бракуем то, что бессмысленно или вредно публиковать: отказ модели, остаток
     * markdown-обёртки, слишком короткий текст, пропущенный/слитый бренд, не
     * упомянутый по имени бренд (особенно целевой №1), AI-штампы.
     *
     * @param Brand[] $brands
     * @return string[]
     */
    private function qualityGate(string $body, array $brands, ?string $keywords = null): array
    {
        $issues = [];

        if ($this->validator->isRefusal($body)) {
            $issues[] = 'модель вернула отказ (нет фактов / чужой корпус)';
        }

        if (str_contains($body, '```')) {
            $issues[] = 'в теле осталась markdown-обёртка ```';
        }

        $words = (int) preg_match_all('/\p{L}+/u', $body);
        if ($words < self::MIN_BODY_WORDS) {
            $issues[] = sprintf('мало слов: %d < %d', $words, self::MIN_BODY_WORDS);
        }

        // Каждый бренд должен иметь секцию «## N. …» — иначе модель слила/пропустила.
        $sections = (int) preg_match_all('/^##\s+\d+\./mu', $body);
        if ($sections < count($brands)) {
            $issues[] = sprintf('секций брендов %d < %d (бренд пропущен или слит)', $sections, count($brands));
        }

        // Каждый бренд упомянут по имени; целевой (первый) — критично.
        foreach ($brands as $idx => $b) {
            $name = (string) $b->getTitle();
            if ($name !== '' && mb_stripos($body, $name) === false) {
                $issues[] = ($idx === 0 ? 'ЦЕЛЕВОЙ бренд' : 'бренд') . " «{$name}» не упомянут по имени";
            }
        }

        // AI-штампы (запрещены промптом — ловим протечки). «отличается»/«выделяется»
        // исключены: в статьях-сравнениях это нормальные слова, не клише (давали ложные
        // отбраковки). Оставляем настоящие маркеры: уникальный/инновационный/передовой/…
        $overBroad = ['отличается', 'выделяется'];
        foreach ($this->validator->getAiPhrases() as $phrase) {
            if (in_array($phrase, $overBroad, true)) {
                continue;
            }
            if (mb_stripos($body, $phrase) !== false) {
                $issues[] = "AI-штамп: «{$phrase}»";
                break;
            }
        }

        // verify-standalone (DrMax): лид-блок «## Коротко» обязан быть и быть самодостаточным
        // (его тащит AI Overview/сниппет) — без отсылок к остальному тексту.
        $issues = array_merge($issues, $this->verifyLead($body));

        // verify-factual-density (DrMax): минимум конкретики (числа + латиница/CAPS — бренды,
        // продукты, цены, размеры). Режет «воду». Порог низкий — не бьёт grounded-тексты.
        $issues = array_merge($issues, $this->verifyFactualDensity($body, $words));

        // intent-coverage (DrMax intent-gap): спросовые интенты из Wordstat должны быть раскрыты.
        $issues = array_merge($issues, $this->intentCoverage($body, $keywords));

        return $issues;
    }

    /**
     * intent-coverage / intent-gap (DrMax): если интент есть в реальном спросе (Wordstat-ключи
     * бренда), он должен быть раскрыт в статье. Лениво: флагуем, только если спрос ЕСТЬ, а в
     * тексте НЕТ ни одного маркера покрытия (маркеры широкие → почти не даёт ложных).
     * @return string[]
     */
    private function intentCoverage(string $body, ?string $keywords): array
    {
        if ($keywords === null || trim($keywords) === '') {
            return [];
        }
        $kw = mb_strtolower($keywords, 'UTF-8');
        $bd = mb_strtolower($body, 'UTF-8');

        $intents = [
            'цена/покупка'      => [['цена', 'цены', 'купить', 'стоимост', 'сколько стоит', 'заказать'],
                                    ['₽', 'руб', 'цена', 'цены', 'стоит', 'стоимост', 'купить', 'заказа', 'прайс']],
            'где купить/локально' => [['где купить', 'адрес', 'шоурум', 'магазин', 'рядом'],
                                    ['магазин', 'шоурум', 'адрес', 'доставк', 'купить', 'на сайте', 'официальн']],
            'размеры'           => [['размер', 'ростовк', 'размерная'],
                                    ['размер', 'ростовк', 'xs', 'xl', '42', '44', '46', '48']],
        ];

        $issues = [];
        foreach ($intents as $name => [$demand, $covered]) {
            $isDemanded = false;
            foreach ($demand as $d) {
                if (mb_strpos($kw, $d) !== false) { $isDemanded = true; break; }
            }
            if (!$isDemanded) {
                continue;
            }
            $isCovered = false;
            foreach ($covered as $c) {
                if (mb_strpos($bd, $c) !== false) { $isCovered = true; break; }
            }
            if (!$isCovered) {
                $issues[] = "интент «{$name}» в спросе, но не раскрыт в статье";
            }
        }

        return $issues;
    }

    /**
     * verify-standalone: лид-блок «## Коротко/Кратко» присутствует и самодостаточен
     * (нет отсылок «ниже/далее/в статье/см.» — иначе невытаскиваемо в сниппет/AIO).
     * @return string[]
     */
    private function verifyLead(string $body): array
    {
        if (!preg_match('/^##\s+(?:Корот|Крат)/mui', $body)) {
            return ['нет лид-блока «## Коротко» (answer-nugget для AI Overview)'];
        }
        if (preg_match('/^##\s+(?:Корот|Крат)\p{L}*\s*(.+?)(?=\n##\s|\z)/smui', $body, $m)) {
            $lead = mb_strtolower($m[1], 'UTF-8');
            if (preg_match('/(?<![\p{L}])(ниже|далее|выше|смотрите|см\.|читайте|в этой статье|в статье|в обзоре|в этом гиде|в гиде|в таблице)(?![\p{L}])/u', $lead, $mm)) {
                return ["лид-блок не самодостаточен: отсылка «{$mm[1]}» (невытаскиваемо в сниппет)"];
            }
        }

        return [];
    }

    /** verify-factual-density: доля «фактовых» токенов (числа + латиница + CAPS-кириллица). @return string[] */
    private function verifyFactualDensity(string $body, int $words): array
    {
        if ($words <= 0) {
            return [];
        }
        $facts = (int) preg_match_all('/\d+/', $body)
            + (int) preg_match_all('/[A-Za-z]{2,}/', $body)
            + (int) preg_match_all('/[А-ЯЁ]{2,}/u', $body);
        $density = $facts / $words;
        if ($density < 0.02) {
            return [sprintf('низкая плотность фактов (%.1f%% < 2%%) — похоже на воду', 100 * $density)];
        }

        return [];
    }

    /**
     * Корректорский LLM-проход (обязательный): чинит опечатки/грамматику до линковки.
     * Гард: если результат заметно короче оригинала (модель «съела» текст) или пуст —
     * откат к оригиналу. Запускается ДО softenCliches/линковки (на голом тексте).
     */
    private function applyProofread(string $body, SymfonyStyle $io): string
    {
        try {
            $clean = $this->llm->proofread($body);
        } catch (\Throwable $e) {
            $io->note('  proofread: ошибка (' . $e->getMessage() . ') — оставлен оригинал');
            return $body;
        }
        $before = (int) preg_match_all('/\p{L}+/u', $body);
        $after  = (int) preg_match_all('/\p{L}+/u', $clean);
        if (trim($clean) === '' || $after < (int) ($before * 0.8)) {
            $io->note(sprintf('  proofread: подозрительный результат (%d→%d слов) — оставлен оригинал', $before, $after));
            return $body;
        }

        return $clean;
    }

    /**
     * Мягкая правка упрямых клише: gemma часто вставляет «уникальн-» вопреки промпту.
     * Замена по корню с сохранением окончания и регистра — снимает ложные отбраковки.
     */
    private function softenCliches(string $body): string
    {
        return (string) preg_replace_callback('/уникальн/iu', static function (array $m): string {
            $first = mb_substr($m[0], 0, 1, 'UTF-8');
            return mb_strtoupper($first, 'UTF-8') === $first ? 'Самобытн' : 'самобытн';
        }, $body);
    }

    private function resolveStyle(Brand $target, string $niche, SymfonyStyle $io): ?BrandStyle
    {
        if ($niche === 'auto') {
            $style = $target->getStyles()->first() ?: null;
            if ($style === null) {
                $io->error('У бренда нет стилей — укажите нишу явно: app:seo:listicle <id> <slug>.');
                return null;
            }
            return $style;
        }

        /** @var BrandStyle|null $style */
        $style = $this->em->getRepository(BrandStyle::class)->findOneBy(['slug' => $niche]);
        if ($style === null) {
            $io->error("Стиль-ниша со slug «{$niche}» не найден.");
            return null;
        }
        return $style;
    }

    /** Факты бренда: описание (он-пейдж истина) + RAG-корпус, обрезано до лимита. */
    private function collectFacts(Brand $brand): string
    {
        $facts = trim((string) $brand->getDescription());
        $ctx   = $this->rag->retrieve($brand)['context'];
        if ($ctx !== null) {
            $facts .= "\n\nДополнительные факты из источников:\n" . $ctx;
        }

        return mb_substr(trim($facts), 0, self::MAX_FACTS_PER_BRAND);
    }

    /** Топ-фразы Wordstat целевого бренда (BrandKeyword), ранжированные. @return string[] */
    private function rankedPhrases(Brand $target, int $limit): array
    {
        /** @var BrandKeywordRepository $kwRepo */
        $kwRepo = $this->em->getRepository(BrandKeyword::class);

        return array_values(array_filter(array_map(
            static fn(BrandKeyword $k) => trim($k->getKeyword()),
            $kwRepo->findByBrandRanked($target, $limit),
        )));
    }

    /** Топ-фразы целевого бренда для SEO-вплетения в текст (до 6, строкой). */
    private function targetKeywords(Brand $target): ?string
    {
        $phrases = $this->rankedPhrases($target, 6);

        return $phrases === [] ? null : implode(', ', $phrases);
    }

    /**
     * FAQ целевого бренда: Wordstat-фразы → вопросы, ответы СТРОГО из фактов
     * (generateBrandFaq сам пропускает фразы без опоры в фактах).
     *
     * @return array<int,array{question:string,answer:string}>
     */
    private function buildFaq(Brand $target, string $facts): array
    {
        $phrases = $this->rankedPhrases($target, 10);
        if ($phrases === [] || trim($facts) === '') {
            return [];
        }

        return $this->llm->generateBrandFaq((string) $target->getTitle(), $phrases, $facts, $target->getCity());
    }

    /** @param array<int,array{question:string,answer:string}> $faq */
    private function buildFaqMarkdown(array $faq): string
    {
        if ($faq === []) {
            return '';
        }

        $blocks = array_map(
            static fn(array $qa) => "**{$qa['question']}**\n\n{$qa['answer']}",
            $faq,
        );

        return "## Частые вопросы\n\n" . implode("\n\n", $blocks);
    }

    /**
     * JSON-LD строим в коде (детерминированно, не доверяем LLM): Article + ItemList
     * (+ FAQPage, если есть FAQ) в @graph. Ссылки ведут на страницы брендов в
     * каталоге → бэклинки на wearbase.ru.
     *
     * @param Brand[]                                            $ordered
     * @param array<int,array{question:string,answer:string}>   $faq
     */
    private function buildJsonLd(string $title, array $ordered, string $persona, array $faq, string $platform, string $campaign): string
    {
        $items = [];
        foreach ($ordered as $i => $b) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $b->getTitle(),
                'url'      => $this->brandUrl($b, $platform, $campaign),
            ];
        }

        if ($platform === 'site') {
            // Свой блог: Article+author даёт шаблон. Цементируем снимок рейтинга (ItemList) + FAQ.
            $graph = [['@type' => 'ItemList', 'itemListElement' => $items]];
        } else {
            $graph = [[
                '@type'      => 'Article',
                'headline'   => $title,
                'author'     => ['@type' => 'Organization', 'name' => 'WEARBASE', 'url' => 'https://wearbase.ru'],
                'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => $items],
            ]];
        }

        if ($faq !== []) {
            $graph[] = [
                '@type'      => 'FAQPage',
                'mainEntity' => array_map(static fn(array $qa) => [
                    '@type'          => 'Question',
                    'name'           => $qa['question'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa['answer']],
                ], $faq),
            ];
        }

        $data = ['@context' => 'https://schema.org', '@graph' => $graph];

        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * URL карточки бренда. Для своего блога (`site`) — внутренняя ссылка БЕЗ UTM
     * (UTM на собственном домене ломает атрибуцию сессий в Метрике). Для внешних
     * площадок — с UTM (бэклинк + атрибуция переходов).
     */
    private function brandUrl(Brand $b, string $platform, string $campaign): string
    {
        if ($platform === 'site') {
            return self::SITE_BASE . '/ru/brands/' . $b->getSlug();
        }

        return sprintf(
            '%s/ru/brands/%s?utm_source=%s&utm_medium=article&utm_campaign=%s',
            self::SITE_BASE,
            $b->getSlug(),
            rawurlencode($platform),
            rawurlencode($campaign),
        );
    }

    /**
     * In-text ссылки на каталог (P1-методология): линкуем ПЕРВОЕ упоминание каждого
     * бренда по имени на его карточку с UTM. Целевой идёт первым → его ссылка
     * попадает в первый абзац («ссылка в первом абзаце»). Заголовки не трогаем
     * (там якоря оглавления), максимум MAX_INTEXT_LINKS ссылок.
     *
     * @param Brand[] $ordered
     */
    private function linkifyBody(string $body, array $ordered, string $platform, string $campaign): string
    {
        $lines  = explode("\n", $body);
        $linked = 0;

        foreach ($ordered as $b) {
            if ($linked >= self::MAX_INTEXT_LINKS) {
                break;
            }
            $name = trim((string) $b->getTitle());
            if ($name === '') {
                continue;
            }
            $url = $this->brandUrl($b, $platform, $campaign);
            // Регистронезависимо (модель часто пишет имя строчными, напр. «tvoe»), но
            // НЕ трогаем уже-ссылки: пропускаем строки с разметкой ссылки на этот бренд.
            $pat = '/(?<![\p{L}\d\/\[\]])' . preg_quote($name, '/') . '(?![\p{L}\d\]\)])/iu';

            foreach ($lines as $idx => $line) {
                if ($line === '' || $line[0] === '#') {       // пропускаем заголовки
                    continue;
                }
                if (str_contains($line, '](')) {              // в строке уже есть ссылка — не плодим
                    continue;
                }
                if (preg_match($pat, $line)) {
                    $lines[$idx] = preg_replace_callback($pat, static fn($m) => "[{$m[0]}]({$url})", $line, 1);
                    $linked++;
                    break;
                }
            }
        }

        return implode("\n", $lines);
    }

    /** Оглавление из H2-секций статьи (UX + анкоры). */
    private function buildToc(string $body): string
    {
        if (!preg_match_all('/^##\s+(.+?)\s*$/mu', $body, $m) || count($m[1]) < 2) {
            return '';
        }
        $items = array_map(static fn(string $h) => '- ' . $h, $m[1]);

        return "## Содержание\n\n" . implode("\n", $items);
    }

    /** CTA-блок со ссылкой на каталог (целевой бренд) с отдельной UTM-кампанией cta-. */
    private function buildCta(Brand $target, string $platform, string $campaign): string
    {
        $url = $this->brandUrl($target, $platform, 'cta-' . $campaign);

        return "## С чего начать\n\n"
            . "Сравните ассортимент и актуальные цены брендов в каталоге WEARBASE — "
            . "там карточки, контакты и ссылки на официальные магазины.\n\n"
            . "[Смотреть бренды в каталоге →]({$url})";
    }

    private function renderDocument(string $title, string $persona, string $platform, string $toc, string $body, string $faqMd, string $cta, string $jsonLd): string
    {
        $tocSection = $toc === '' ? '' : "{$toc}\n\n";
        $faqSection = $faqMd === '' ? '' : "\n\n{$faqMd}";
        $ctaSection = $cta === '' ? '' : "\n\n{$cta}";
        $ld = $jsonLd === '' ? '' : "\n\n---\n\n<script type=\"application/ld+json\">\n{$jsonLd}\n</script>";

        return <<<MD
        <!-- площадка: {$platform} · автор-персона: {$persona} -->

        # {$title}

        {$tocSection}{$body}{$faqSection}{$ctaSection}{$ld}
        MD;
    }

    private function saveDocument(string $outDir, BrandStyle $style, Brand $target, string $platform, int $personaIdx, string $document, SymfonyStyle $io): string
    {
        if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            $io->warning("Не удалось создать {$outDir} — пишу в текущую папку.");
            $outDir = '.';
        }

        $file = sprintf(
            '%s/listicle-%s-%s-%s-p%d.md',
            rtrim($outDir, '/'),
            $style->getSlug(),
            $target->getSlug(),
            $platform,
            $personaIdx % count(self::PERSONAS),
        );
        file_put_contents($file, $document);

        return $file;
    }
}
