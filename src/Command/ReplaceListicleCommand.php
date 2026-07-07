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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Серия «Бренд X ушёл — чем заменить в России» (docs/foreign_brands_policy.md,
 * раздел «Фрейминг»): по кураторскому списку якорей (config/seo/replacement_anchors.yaml)
 * генерирует статьи «Чем заменить {X} в России: {N} российских брендов». X — ушедший
 * иностранный бренд (origin_status='foreign'), только нейтральная точка отсчёта
 * (nominative use): не линкуется, не входит в ItemList, страница X не создаётся.
 * Замены — российские бренды той же ниши через findListicleCompetitors (он уже
 * фильтрует foreign/inactive/off-niche), описываются СТРОГО из фактов (grounded).
 *
 * На каждый якорь — ДВЕ копии: site (canonical для блога, {out}/blog/replace-{x}-site.md)
 * и dzen ({out}/dzen/replace-{x}-dzen.md, без таблиц). Общая база имени replace-{x} +
 * суффиксы -site/-dzen — под ArticleDistributionAttacher::topicKey.
 *
 *   php bin/console app:seo:replace-listicle --dry-run          # план без генерации
 *   php bin/console app:seo:replace-listicle --anchor=zara      # один якорь
 *   php bin/console app:seo:replace-listicle --limit=2
 *
 * TODO: общие хелперы сборки дублируются с GenerateListicleCommand/SeoGuideCommand —
 * вынос в SeoArticleAssembly = отдельный follow-up (не трогаем оттестированный листикл).
 */
#[AsCommand(
    name: 'app:seo:replace-listicle',
    description: 'SEO: статья «Чем заменить {ушедший бренд X} в России: N российских брендов» (grounded)',
)]
class ReplaceListicleCommand extends Command
{
    private const SITE_BASE = 'https://wearbase.ru';
    private const ANCHORS_FILE = '/config/seo/replacement_anchors.yaml';
    private const MAX_FACTS_PER_BRAND = 2500;
    private const MIN_BODY_WORDS = 700;
    private const MAX_INTEXT_LINKS = 4;
    private const MAX_GEN_ATTEMPTS = 3;       // self-heal: попыток с точечной правкой по gate
    private const MIN_REPLACEMENTS = 3;       // меньше — статья-замена не имеет смысла
    private const PLATFORMS = ['site', 'dzen'];

    /** Авторы-персоны (ротация по индексу якоря). */
    private const PERSONAS = [
        'независимый fashion-стилист из Москвы',
        'маркетолог в fashion-ритейле',
        'автор блога про осознанное потребление и российские бренды',
        'журналист, пишущий о моде и локальных дизайнерах',
        'предприниматель в e-commerce одежды',
        'покупатель-энтузиаст, который делится личным опытом',
    ];

    /** Тон под площадку (пара site/dzen из PLATFORM_TONES донора). */
    private const PLATFORM_TONES = [
        'site' => 'редакционная статья каталога WEARBASE для собственного блога: информативно, по-доброму экспертно, без рекламного давления',
        'dzen' => 'статья для Яндекс.Дзена: информативно и экспертно, но живым языком, с пользой и вовлечением читателя',
    ];

    /** Replacement-интент во фразах Wordstat якоря X. */
    private const REPLACEMENT_KEYWORD_PATTERN = '/в россии|как называется|аналог|замен|вместо|ушел|ушёл/iu';

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
            ->addOption('anchor',  null, InputOption::VALUE_REQUIRED, 'Обработать один якорь по slug бренда X')
            ->addOption('limit',   null, InputOption::VALUE_REQUIRED, 'Максимум якорей за прогон')
            ->addOption('out',     null, InputOption::VALUE_REQUIRED, 'Базовая папка ({out}/blog + {out}/dzen)', 'var/seo')
            ->addOption('force',   null, InputOption::VALUE_NONE,     'Сохранять даже при провале quality-gate (с предупреждением)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Показать план (X, ниша, замены, ключевики) без генерации')
            ->addOption('persona', null, InputOption::VALUE_REQUIRED, 'Стартовый индекс автора-персоны (0..' . (count(self::PERSONAS) - 1) . ')', '0')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $anchorSlug   = $input->getOption('anchor');
        $limit        = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;
        $outDir       = rtrim((string) $input->getOption('out'), '/');
        $force        = (bool) $input->getOption('force');
        $dryRun       = (bool) $input->getOption('dry-run');
        $personaStart = (int) $input->getOption('persona');

        $anchors = $this->loadAnchors($io);
        if ($anchors === null) {
            return Command::FAILURE;
        }
        if ($anchorSlug !== null) {
            $anchors = array_values(array_filter($anchors, static fn(array $a) => $a['foreign'] === $anchorSlug));
            if ($anchors === []) {
                $io->error("Якорь «{$anchorSlug}» не найден в " . self::ANCHORS_FILE);
                return Command::FAILURE;
            }
        }
        if ($limit !== null) {
            $anchors = array_slice($anchors, 0, $limit);
        }

        $io->title('SEO · серия «Чем заменить ушедший бренд X в России»' . ($dryRun ? ' — DRY-RUN' : ''));

        /** @var BrandRepository $brandRepo */
        $brandRepo = $this->em->getRepository(Brand::class);

        $ok = 0;
        $rejected = 0;
        $skipped = 0;

        foreach ($anchors as $idx => $cfg) {
            $slug = (string) $cfg['foreign'];
            $io->section("Якорь: {$slug}");

            /** @var Brand|null $anchor */
            $anchor = $brandRepo->findOneBy(['slug' => $slug]);
            if ($anchor === null || !$anchor->getTitle()) {
                $io->warning("Бренд X «{$slug}» не найден в БД (или без названия) — пропуск.");
                $skipped++;
                continue;
            }
            if ($anchor->getOriginStatus() !== 'foreign') {
                $io->warning(sprintf('«%s»: origin_status=%s, а не foreign — пропуск (серия только про ушедшие иностранные).',
                    $slug, $anchor->getOriginStatus() ?? 'null'));
                $skipped++;
                continue;
            }

            $style = $this->resolveStyle($anchor, $cfg['niche'], $io);
            if ($style === null) {
                $skipped++;
                continue;
            }

            $count = max(2, min(10, (int) ($cfg['count'] ?? 6)));
            // findListicleCompetitors фильтрует inactive/off-niche/foreign + дедуп по имени
            // (X исключён и по id, и по названию), но пропускает origin_status='unknown' —
            // среди них есть иностранцы (Barena Venezia, Венеция). Серия обещает РОССИЙСКИЕ
            // замены, поэтому строгий пост-фильтр origin='ru'; берём с запасом ×2 и режем.
            $replacements = array_slice(
                array_filter(
                    $brandRepo->findListicleCompetitors((int) $style->getId(), (int) $anchor->getId(), $count * 2, null, $anchor->getTitle()),
                    static fn(Brand $b) => $b->getOriginStatus() === 'ru',
                ),
                0,
                $count,
            );
            if (count($replacements) < self::MIN_REPLACEMENTS) {
                $io->warning(sprintf('«%s»: в нише «%s» нашлось только %d российских замен (< %d) — пропуск.',
                    $slug, $style->getTitle(), count($replacements), self::MIN_REPLACEMENTS));
                $skipped++;
                continue;
            }

            $keywords = $this->replacementKeywords($anchor, (array) ($cfg['keywords'] ?? []));

            $io->definitionList(
                ['Бренд X'   => sprintf('%s (id %d)', $anchor->getTitle(), $anchor->getId())],
                ['Ниша'      => sprintf('%s (%s)', $style->getTitle(), $style->getSlug())],
                ['Замен'     => count($replacements) . ': ' . implode(', ', array_map(static fn(Brand $b) => (string) $b->getTitle(), $replacements))],
                ['Ключевики' => $keywords ?? '— (нет replacement-фраз)'],
                ['Персона'   => self::PERSONAS[($personaStart + $idx) % count(self::PERSONAS)]],
            );

            if ($dryRun) {
                continue;
            }

            $io->text('Сбор фактов (описание + RAG)…');
            $llmBrands = [];
            foreach ($replacements as $b) {
                $facts = $this->collectFacts($b);
                $llmBrands[] = ['name' => (string) $b->getTitle(), 'city' => $b->getCity(), 'facts' => $facts];
                $io->text(sprintf('  · %s — %d симв. фактов', $b->getTitle(), mb_strlen($facts)));
            }

            $persona = self::PERSONAS[($personaStart + $idx) % count(self::PERSONAS)];
            foreach (self::PLATFORMS as $platform) {
                if ($this->generateForPlatform($anchor, $style, $replacements, $llmBrands, $keywords, $persona, $platform, $outDir, $force, $io)) {
                    $ok++;
                } else {
                    $rejected++;
                }
            }
        }

        $io->newLine();
        $io->table(['Результат', 'Кол-во'], [
            ['Готово',            $ok],
            ['Отбраковано/пусто', $rejected],
            ['Пропущено якорей',  $skipped],
        ]);

        if ($dryRun) {
            return Command::SUCCESS;
        }

        return $ok > 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Генерация одной копии (площадка site|dzen) с self-heal циклом, гейтами и сохранением.
     *
     * @param Brand[]                                             $replacements
     * @param array<int,array{name:string,city:?string,facts:string}> $llmBrands
     */
    private function generateForPlatform(
        Brand $anchor,
        BrandStyle $style,
        array $replacements,
        array $llmBrands,
        ?string $keywords,
        string $persona,
        string $platform,
        string $outDir,
        bool $force,
        SymfonyStyle $io,
    ): bool {
        $anchorName = (string) $anchor->getTitle();
        $tone       = self::PLATFORM_TONES[$platform];

        $io->text(sprintf('Генерация · %s · %s', $platform, $persona));

        // Self-heal как в доноре: до MAX_GEN_ATTEMPTS попыток, на провал гейта чиним
        // ИМЕННО findings (fixHint), температура 0.7→0.6→0.5.
        $temps = [0.7, 0.6, 0.5];
        $fixHint = null;
        $body = null;
        $issues = ['пусто'];
        for ($att = 0; $att < self::MAX_GEN_ATTEMPTS; $att++) {
            try {
                $raw = $this->llm->generateReplacementListicle($anchorName, (string) $style->getTitle(), $llmBrands, $persona, $tone, $keywords, $fixHint, $temps[$att] ?? 0.5, noTables: $platform === 'dzen');
            } catch (\Throwable $e) {
                // LLM-блип (gemma под майнингом перезапускается) — ждём и ретраим.
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
            $issues = $this->qualityGate($raw, $replacements, $anchorName, $keywords);
            $body = $raw;
            if ($issues === []) {
                break;
            }
            $fixHint = implode('; ', $issues);
            $io->text(sprintf('  попытка %d/%d → gate: %s', $att + 1, self::MAX_GEN_ATTEMPTS, $fixHint));
        }

        if ($body === null || ($issues !== [] && !$force)) {
            $io->warning('  Отбраковано после ' . self::MAX_GEN_ATTEMPTS . ' попыток: ' . implode('; ', $issues));
            return false;
        }
        if ($issues !== [] && $force) {
            $io->note('  quality-gate (проигнорирован --force): ' . implode('; ', $issues));
        }

        // Корректура — один раз на принятом черновике (+ повторный softenCliches).
        $body = $this->softenCliches($this->applyProofread($body, $io));

        // X в title — по построению (гейт (a) для title закрыт детерминированно).
        $title    = sprintf('Чем заменить %s в России: %d российских брендов (%s)', $anchorName, count($replacements), date('Y'));
        $campaign = sprintf('replace-%s-%s', $anchor->getSlug(), $platform);

        // X НЕ входит в $replacements → не линкуется и не попадает в ItemList.
        $linkedBody = $this->linkifyBody($body, $replacements, $platform, $campaign);
        $toc        = $this->buildToc($linkedBody);
        $cta        = $this->buildCta($platform, $campaign);
        $jsonLd     = $this->buildJsonLd($title, $replacements, $platform, $campaign);
        $document   = $this->renderDocument($title, $persona, $platform, $toc, $linkedBody, $cta, $jsonLd);

        $path = $this->saveDocument($outDir, (string) $anchor->getSlug(), $platform, $document, $io);
        $io->success("Сохранено: {$path}");

        return true;
    }

    /**
     * Кураторский список якорей из config/seo/replacement_anchors.yaml.
     *
     * @return array<int,array{foreign:string,niche:?string,count:int,keywords:array}>|null
     */
    private function loadAnchors(SymfonyStyle $io): ?array
    {
        $file = \dirname(__DIR__, 2) . self::ANCHORS_FILE;
        if (!is_file($file)) {
            $io->error("Нет файла якорей: {$file}");
            return null;
        }

        $data = Yaml::parseFile($file);
        $out = [];
        foreach (($data['anchors'] ?? []) as $a) {
            $slug = trim((string) ($a['foreign'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $out[] = [
                'foreign'  => $slug,
                'niche'    => isset($a['niche']) && $a['niche'] !== null && $a['niche'] !== '' ? (string) $a['niche'] : null,
                'count'    => (int) ($a['count'] ?? 6),
                'keywords' => is_array($a['keywords'] ?? null) ? $a['keywords'] : [],
            ];
        }
        if ($out === []) {
            $io->error("Файл якорей пуст: {$file}");
            return null;
        }

        return $out;
    }

    /** Ниша: slug из yaml или первый style бренда X; нет ни того ни другого → null (скип). */
    private function resolveStyle(Brand $anchor, ?string $nicheSlug, SymfonyStyle $io): ?BrandStyle
    {
        if ($nicheSlug !== null) {
            /** @var BrandStyle|null $style */
            $style = $this->em->getRepository(BrandStyle::class)->findOneBy(['slug' => $nicheSlug]);
            if ($style === null) {
                $io->warning("Стиль-ниша «{$nicheSlug}» не найдена — пропуск якоря «{$anchor->getSlug()}».");
            }
            return $style;
        }

        $style = $anchor->getStyles()->first() ?: null;
        if ($style === null) {
            $io->warning("У бренда X «{$anchor->getSlug()}» нет стилей и niche в yaml не задан — пропуск.");
        }

        return $style;
    }

    /**
     * Ключевики статьи: replacement-фразы X из brand_keyword («в россии», «как называется»,
     * «аналог», «замен», «вместо», «ушел») + доп. фразы из yaml. До 6, строкой.
     *
     * @param string[] $extra
     */
    private function replacementKeywords(Brand $anchor, array $extra): ?string
    {
        /** @var BrandKeywordRepository $kwRepo */
        $kwRepo = $this->em->getRepository(BrandKeyword::class);

        // Окно широкое (не топ-N): replacement-фразы — среднечастотный хвост, у массовых
        // якорей (zara: «zara в россии» ниже топ-30 из 100) в узком окне их не видно.
        $phrases = [];
        foreach ($kwRepo->findByBrandRanked($anchor, 300) as $k) {
            $p = trim($k->getKeyword());
            if ($p !== '' && preg_match(self::REPLACEMENT_KEYWORD_PATTERN, $p)) {
                $phrases[] = $p;
            }
        }
        foreach ($extra as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $phrases[] = $p;
            }
        }
        $phrases = array_slice(array_values(array_unique($phrases)), 0, 6);

        return $phrases === [] ? null : implode(', ', $phrases);
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

    /**
     * Quality-gate (вариант листикла-донора без семантики «целевой №1» — все N замен
     * равны) + два специфичных для серии: X упомянут в лид-блоке «## Коротко»
     * (в title — по построению) и legal-denylist (подделка/реплика/аффилированность/
     * оценки «лучше/хуже чем X» — юридический брак, не стилистический).
     *
     * @param Brand[] $brands
     * @return string[]
     */
    private function qualityGate(string $body, array $brands, string $anchorName, ?string $keywords = null): array
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

        // Каждая замена должна иметь секцию «## N. …» — иначе модель слила/пропустила.
        $sections = (int) preg_match_all('/^##\s+\d+\./mu', $body);
        if ($sections < count($brands)) {
            $issues[] = sprintf('секций брендов %d < %d (бренд пропущен или слит)', $sections, count($brands));
        }

        // Каждая замена упомянута по имени (все равны — без выделения «целевого»).
        foreach ($brands as $b) {
            $name = (string) $b->getTitle();
            if ($name !== '' && mb_stripos($body, $name) === false) {
                $issues[] = "бренд «{$name}» не упомянут по имени";
            }
        }

        // (a) X — точка отсчёта: обязан быть в лид-блоке «## Коротко» (прямой ответ
        // «чем заменить X»); в title X попадает детерминированно при сборке.
        $issues = array_merge($issues, $this->verifyAnchorInLead($body, $anchorName));

        // (b) legal-denylist: nominative use не терпит подделок/аффилированности/оценок X.
        $issues = array_merge($issues, $this->legalDenylist($body, $anchorName));

        // AI-штампы (запрещены промптом — ловим протечки).
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

        $issues = array_merge($issues, $this->verifyLead($body));
        $issues = array_merge($issues, $this->verifyFactualDensity($body, $words));
        $issues = array_merge($issues, $this->intentCoverage($body, $keywords));

        return $issues;
    }

    /** X упомянут в лид-блоке «## Коротко» (ответ «чем заменить X» без X бессмыслен). @return string[] */
    private function verifyAnchorInLead(string $body, string $anchorName): array
    {
        if (!preg_match('/^##\s+(?:Корот|Крат)\p{L}*\s*(.+?)(?=\n##\s|\z)/smui', $body, $m)) {
            return []; // отсутствие самого лид-блока флагует verifyLead — не дублируем
        }
        if (mb_stripos($m[1], $anchorName) === false) {
            return ["бренд X «{$anchorName}» не упомянут в блоке «## Коротко»"];
        }

        return [];
    }

    /** Legal-denylist: юридически опасные формулировки про X — безусловный брак. @return string[] */
    private function legalDenylist(string $body, string $anchorName): array
    {
        $x = preg_quote($anchorName, '/');
        if (preg_match('/подделк|контрафакт|реплик|копия бренда|официальн\w*\s+представител|(лучше|хуже),?\s+чем\s+' . $x . '/iu', $body, $m)) {
            return ["legal-denylist: «{$m[0]}»"];
        }

        return [];
    }

    /** intent-coverage (DrMax intent-gap): спросовый интент из Wordstat должен быть раскрыт. @return string[] */
    private function intentCoverage(string $body, ?string $keywords): array
    {
        if ($keywords === null || trim($keywords) === '') {
            return [];
        }
        $kw = mb_strtolower($keywords, 'UTF-8');
        $bd = mb_strtolower($body, 'UTF-8');

        $intents = [
            'цена/покупка'        => [['цена', 'цены', 'купить', 'стоимост', 'сколько стоит', 'заказать'],
                                      ['₽', 'руб', 'цена', 'цены', 'стоит', 'стоимост', 'купить', 'заказа', 'прайс']],
            'где купить/локально' => [['где купить', 'адрес', 'шоурум', 'магазин', 'рядом'],
                                      ['магазин', 'шоурум', 'адрес', 'доставк', 'купить', 'на сайте', 'официальн']],
            'размеры'             => [['размер', 'ростовк', 'размерная'],
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

    /** verify-standalone: лид-блок «## Коротко» присутствует и самодостаточен. @return string[] */
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
     * Корректорский LLM-проход: чинит опечатки/грамматику до линковки.
     * Гард: пустой/заметно короче оригинала → откат к оригиналу.
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
     * Замена по корню с сохранением окончания и регистра.
     */
    private function softenCliches(string $body): string
    {
        return (string) preg_replace_callback('/уникальн/iu', static function (array $m): string {
            $first = mb_substr($m[0], 0, 1, 'UTF-8');
            return mb_strtoupper($first, 'UTF-8') === $first ? 'Самобытн' : 'самобытн';
        }, $body);
    }

    /**
     * URL карточки бренда: свой блог (`site`) — без UTM (не ломать атрибуцию Метрики),
     * внешние площадки — с UTM.
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
     * In-text ссылки на каталог: первое упоминание каждой замены → карточка бренда.
     * X в $ordered НЕ входит → не линкуется. Заголовки не трогаем, максимум
     * MAX_INTEXT_LINKS ссылок.
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

    /** CTA-блок на каталог (все замены равны — ведём на общий каталог, не на бренд). */
    private function buildCta(string $platform, string $campaign): string
    {
        $url = $platform === 'site'
            ? self::SITE_BASE . '/ru/brands'
            : sprintf('%s/ru/brands?utm_source=%s&utm_medium=article&utm_campaign=cta-%s',
                self::SITE_BASE, rawurlencode($platform), rawurlencode($campaign));

        return "## С чего начать\n\n"
            . "Сравните ассортимент и актуальные цены брендов в каталоге WEARBASE — "
            . "там карточки, контакты и ссылки на официальные магазины.\n\n"
            . "[Смотреть бренды в каталоге →]({$url})";
    }

    /**
     * JSON-LD детерминированно в коде: ItemList из замен (X не входит). Для своего
     * блога — только ItemList (Article+author даёт шаблон), для внешних — Article.
     *
     * @param Brand[] $ordered
     */
    private function buildJsonLd(string $title, array $ordered, string $platform, string $campaign): string
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
            $graph = [['@type' => 'ItemList', 'itemListElement' => $items]];
        } else {
            $graph = [[
                '@type'      => 'Article',
                'headline'   => $title,
                'author'     => ['@type' => 'Organization', 'name' => 'WEARBASE', 'url' => 'https://wearbase.ru'],
                'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => $items],
            ]];
        }

        $data = ['@context' => 'https://schema.org', '@graph' => $graph];

        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function renderDocument(string $title, string $persona, string $platform, string $toc, string $body, string $cta, string $jsonLd): string
    {
        $tocSection = $toc === '' ? '' : "{$toc}\n\n";
        $ctaSection = $cta === '' ? '' : "\n\n{$cta}";
        $ld = $jsonLd === '' ? '' : "\n\n---\n\n<script type=\"application/ld+json\">\n{$jsonLd}\n</script>";

        return <<<MD
        <!-- площадка: {$platform} · автор-персона: {$persona} -->

        # {$title}

        {$tocSection}{$body}{$ctaSection}{$ld}
        MD;
    }

    /**
     * Сохранение: общая база имени replace-{xSlug} + суффикс -{platform} — под
     * ArticleDistributionAttacher::topicKey (site-копия ложится в {out}/blog как
     * canonical для publish-blog, dzen-копия — в {out}/dzen).
     */
    private function saveDocument(string $outDir, string $anchorSlug, string $platform, string $document, SymfonyStyle $io): string
    {
        $dir = $outDir . '/' . ($platform === 'site' ? 'blog' : $platform);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $io->warning("Не удалось создать {$dir} — пишу в текущую папку.");
            $dir = '.';
        }

        $file = sprintf('%s/replace-%s-%s.md', rtrim($dir, '/'), $anchorSlug, $platform);
        file_put_contents($file, $document);

        return $file;
    }
}
