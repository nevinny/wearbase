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
    private const MIN_BODY_WORDS = 200;       // quality-gate: минимум слов в теле статьи

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
        $competitors = $brandRepo->findListicleCompetitors((int) $style->getId(), $brandId, $top - 1);

        if ($competitors === []) {
            $io->error(sprintf(
                'В нише «%s» нет конкурентов с описанием, кроме целевого бренда. Выберите другую нишу.',
                $style->getTitle(),
            ));
            return Command::FAILURE;
        }

        $nicheTitle = (string) $style->getTitle();

        $io->title('SEO Boost · листикл «ТОП-N в нише»');
        $io->definitionList(
            ['Ниша'     => $nicheTitle],
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
            $body = $this->llm->generateListicle($nicheTitle, $llmBrands, $vPersona, $vTone, $keywords);
            if (trim($body) === '') {
                $io->warning('  LLM вернула пустой результат — вариант пропущен.');
                $rejected++;
                continue;
            }

            // Quality-gate: не сохраняем брак (пропущенный бренд, отказ, мусор, штампы).
            $issues = $this->qualityGate($body, $orderedBrands);
            if ($issues !== []) {
                if ($force) {
                    $io->note('  quality-gate (проигнорирован --force): ' . implode('; ', $issues));
                } else {
                    $io->warning('  Отбраковано quality-gate:');
                    $io->listing($issues);
                    $rejected++;
                    continue;
                }
            }

            $title    = sprintf('ТОП-%d брендов %s: рейтинг %s', count($orderedBrands), $nicheTitle, date('Y'));
            $faqMd    = $this->buildFaqMarkdown($faq);
            $jsonLd   = $this->buildJsonLd($title, $orderedBrands, $vPersona, $faq);
            $document = $this->renderDocument($title, $vPersona, $vPlatform, $body, $faqMd, $jsonLd);

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
    private function qualityGate(string $body, array $brands): array
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

        // AI-штампы (запрещены промптом — ловим протечки).
        foreach ($this->validator->getAiPhrases() as $phrase) {
            if (mb_stripos($body, $phrase) !== false) {
                $issues[] = "AI-штамп: «{$phrase}»";
                break;
            }
        }

        return $issues;
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
    private function buildJsonLd(string $title, array $ordered, string $persona, array $faq = []): string
    {
        $items = [];
        foreach ($ordered as $i => $b) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $b->getTitle(),
                'url'      => self::SITE_BASE . '/ru/brands/' . $b->getSlug(),
            ];
        }

        $graph = [[
            '@type'      => 'Article',
            'headline'   => $title,
            'author'     => ['@type' => 'Person', 'name' => mb_convert_case($persona, MB_CASE_TITLE, 'UTF-8')],
            'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => $items],
        ]];

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

    private function renderDocument(string $title, string $persona, string $platform, string $body, string $faqMd, string $jsonLd): string
    {
        $faqSection = $faqMd === '' ? '' : "\n\n{$faqMd}";

        return <<<MD
        <!-- площадка: {$platform} · автор-персона: {$persona} -->

        # {$title}

        {$body}{$faqSection}

        ---

        <script type="application/ld+json">
        {$jsonLd}
        </script>
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
