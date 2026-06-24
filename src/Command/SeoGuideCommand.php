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
 * SEO/GEO P2: информационный гид-обзор по нише (опц. городу) — для длинного хвоста,
 * где брендов мало для рейтинга-листикла (83 ячейки стиль×город из 102 имеют 1 бренд,
 * см. docs/seo_boost.md). В отличие от `app:seo:listicle` — без «ТОП-N» и целевого №1:
 * нейтральный обзор всех брендов ниши. Анатомия P1 (оглавление, ссылки+UTM, CTA,
 * JSON-LD), grounded из описаний+RAG. Для внешней публикации (Дзен и т.д.).
 *
 *   php bin/console app:seo:guide streetwear
 *   php bin/console app:seo:guide casual --city=москва --platform=dzen --limit=8
 *
 * TODO: общие хелперы сборки дублируются с GenerateListicleCommand — вынести в
 * трейт/сервис SeoArticleAssembly (follow-up, чтобы не трогать оттестированный листикл).
 */
#[AsCommand(
    name: 'app:seo:guide',
    description: 'SEO/GEO: информационный гид-обзор по нише/городу (для длинного хвоста, без рейтинга)',
)]
class SeoGuideCommand extends Command
{
    private const SITE_BASE = 'https://wearbase.ru';
    private const MAX_FACTS_PER_BRAND = 2000;
    private const MIN_BODY_WORDS = 700;
    private const MAX_INTEXT_LINKS = 5;

    private const PERSONAS = [
        'независимый fashion-стилист из Москвы',
        'маркетолог в fashion-ритейле',
        'автор блога про осознанное потребление и российские бренды',
        'журналист, пишущий о моде и локальных дизайнерах',
        'предприниматель в e-commerce одежды',
        'покупатель-энтузиаст, который делится личным опытом',
    ];

    private const PLATFORM_TONES = [
        'vc'     => 'экспертный аналитический обзор рынка, по-деловому',
        'dtf'    => 'личный тест-обзор, неформально, от первого лица',
        'pikabu' => 'личный опыт простым разговорным языком (UGC)',
        'press'  => 'нейтрально и сдержанно',
        'blog'   => 'личный блог, доверительно',
        'dzen'   => 'статья для Яндекс.Дзена: информативно и экспертно, живым языком, с пользой для читателя',
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
            ->addArgument('niche', InputArgument::REQUIRED, 'Slug стиля-ниши')
            ->addOption('city',     null, InputOption::VALUE_REQUIRED, 'Гео-срез: бренды только из этого города')
            ->addOption('limit',    null, InputOption::VALUE_REQUIRED, 'Сколько брендов осветить', '8')
            ->addOption('platform', null, InputOption::VALUE_REQUIRED, 'Площадка/тон: ' . implode('|', array_keys(self::PLATFORM_TONES)), 'dzen')
            ->addOption('persona',  null, InputOption::VALUE_REQUIRED, 'Индекс автора-персоны (0..' . (count(self::PERSONAS) - 1) . ')', '0')
            ->addOption('force',    null, InputOption::VALUE_NONE,     'Сохранять вопреки quality-gate')
            ->addOption('out',      null, InputOption::VALUE_REQUIRED, 'Папка (- = консоль)', 'var/seo/guides')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $niche    = (string) $input->getArgument('niche');
        $city     = $input->getOption('city');
        $limit    = max(1, (int) $input->getOption('limit'));
        $platform = (string) $input->getOption('platform');
        $persona  = self::PERSONAS[((int) $input->getOption('persona')) % count(self::PERSONAS)];
        $force    = (bool) $input->getOption('force');
        $outDir   = (string) $input->getOption('out');

        if (!isset(self::PLATFORM_TONES[$platform])) {
            $io->error("Неизвестная площадка «{$platform}».");
            return Command::FAILURE;
        }

        /** @var BrandStyle|null $style */
        $style = $this->em->getRepository(BrandStyle::class)->findOneBy(['slug' => $niche]);
        if ($style === null) {
            $io->error("Стиль-ниша «{$niche}» не найдена.");
            return Command::FAILURE;
        }

        /** @var BrandRepository $repo */
        $repo   = $this->em->getRepository(Brand::class);
        $brands = $repo->findListicleCompetitors((int) $style->getId(), 0, $limit, $city); // excludeId=0 → все
        if ($brands === []) {
            $io->error('Нет брендов с описанием в этой нише/городе.');
            return Command::FAILURE;
        }

        $cityDisp   = $city ? mb_convert_case(trim((string) $city), MB_CASE_TITLE, 'UTF-8') : null;
        $nicheTitle = $cityDisp ? sprintf('%s в городе %s', $style->getTitle(), $cityDisp) : (string) $style->getTitle();
        $tone       = self::PLATFORM_TONES[$platform];

        $io->title('SEO · информационный гид');
        $io->definitionList(
            ['Ниша' => $nicheTitle],
            ['Брендов' => count($brands)],
            ['Площадка' => $platform],
            ['Автор' => $persona],
        );

        $io->section('Сбор фактов (описание + RAG)');
        $llmBrands = [];
        foreach ($brands as $b) {
            $facts = $this->collectFacts($b);
            $llmBrands[] = ['name' => (string) $b->getTitle(), 'city' => $b->getCity(), 'facts' => $facts];
            $io->text(sprintf('  · %s — %d симв.', $b->getTitle(), mb_strlen($facts)));
        }
        $keywords = $this->keywordsFor($brands[0]);

        $io->section('Генерация гида (локальная LLM)');
        $body = $this->llm->generateGuide($nicheTitle, $cityDisp, $llmBrands, $persona, $tone, $keywords);
        if (trim($body) === '') {
            $io->error('LLM вернула пусто.');
            return Command::FAILURE;
        }
        $body = $this->softenCliches($body);

        $issues = $this->qualityGate($body, $brands);
        if ($issues !== []) {
            if ($force) {
                $io->note('quality-gate (--force): ' . implode('; ', $issues));
            } else {
                $io->warning('Отбраковано quality-gate:');
                $io->listing($issues);
                return Command::FAILURE;
            }
        }

        $title    = $cityDisp
            ? sprintf('%s: гид по брендам в городе %s, %s', $style->getTitle(), $cityDisp, date('Y'))
            : sprintf('%s: гид по российским брендам %s', $style->getTitle(), date('Y'));
        $campaign = sprintf('guide-%s%s-%s', $style->getSlug(), $city ? '-' . mb_strtolower((string) $city, 'UTF-8') : '', $platform);

        $linkedBody = $this->linkifyBody($body, $brands, $platform, $campaign);
        $toc        = $this->buildToc($linkedBody);
        $cta        = $this->buildCta($platform, $campaign);
        $jsonLd     = $this->buildJsonLd($title, $brands, $persona, $platform, $campaign);
        $document   = $this->renderDocument($title, $persona, $platform, $toc, $linkedBody, $cta, $jsonLd);

        if ($outDir === '-') {
            $output->writeln($document);
        } else {
            $path = $this->saveDocument($outDir, $style, $city, $platform, $document, $io);
            $io->success("Сохранено: {$path}");
        }

        return Command::SUCCESS;
    }

    private function collectFacts(Brand $brand): string
    {
        $facts = trim((string) $brand->getDescription());
        $ctx   = $this->rag->retrieve($brand)['context'];
        if ($ctx !== null) {
            $facts .= "\n\nДополнительные факты из источников:\n" . $ctx;
        }

        return mb_substr(trim($facts), 0, self::MAX_FACTS_PER_BRAND);
    }

    private function keywordsFor(Brand $brand): ?string
    {
        /** @var BrandKeywordRepository $kwRepo */
        $kwRepo  = $this->em->getRepository(BrandKeyword::class);
        $phrases = array_values(array_filter(array_map(
            static fn(BrandKeyword $k) => trim($k->getKeyword()),
            $kwRepo->findByBrandRanked($brand, 6),
        )));

        return $phrases === [] ? null : implode(', ', $phrases);
    }

    /**
     * Мягкая правка упрямых клише: локальная gemma часто вставляет «уникальн-»
     * вопреки запрету в промпте. Меняем по корню, сохраняя окончание и регистр
     * (уникальная→самобытная, Уникальный→Самобытный). Снимает ложные отбраковки gate.
     */
    private function softenCliches(string $body): string
    {
        return (string) preg_replace_callback('/уникальн/iu', static function (array $m): string {
            $first = mb_substr($m[0], 0, 1, 'UTF-8');
            $upper = mb_strtoupper($first, 'UTF-8') === $first;
            return $upper ? 'Самобытн' : 'самобытн';
        }, $body);
    }

    /** Гид-вариант gate: без проверки нумерованных секций (у гида «## Название», не «## N.»). */
    private function qualityGate(string $body, array $brands): array
    {
        $issues = [];
        if ($this->validator->isRefusal($body)) {
            $issues[] = 'отказ модели';
        }
        if (str_contains($body, '```')) {
            $issues[] = 'остаток ```';
        }
        $words = (int) preg_match_all('/\p{L}+/u', $body);
        if ($words < self::MIN_BODY_WORDS) {
            $issues[] = sprintf('мало слов: %d < %d', $words, self::MIN_BODY_WORDS);
        }
        // Хотя бы половина брендов упомянута по имени (гид может не вместить все).
        $mentioned = 0;
        foreach ($brands as $b) {
            if (mb_stripos($body, (string) $b->getTitle()) !== false) {
                $mentioned++;
            }
        }
        if ($mentioned < max(1, (int) ceil(count($brands) / 2))) {
            $issues[] = sprintf('упомянуто брендов %d из %d — мало', $mentioned, count($brands));
        }
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

        return $issues;
    }

    private function brandUrl(Brand $b, string $platform, string $campaign): string
    {
        return sprintf(
            '%s/ru/brands/%s?utm_source=%s&utm_medium=article&utm_campaign=%s',
            self::SITE_BASE,
            $b->getSlug(),
            rawurlencode($platform),
            rawurlencode($campaign),
        );
    }

    /** @param Brand[] $brands */
    private function linkifyBody(string $body, array $brands, string $platform, string $campaign): string
    {
        $lines  = explode("\n", $body);
        $linked = 0;
        foreach ($brands as $b) {
            if ($linked >= self::MAX_INTEXT_LINKS) {
                break;
            }
            $name = trim((string) $b->getTitle());
            if ($name === '') {
                continue;
            }
            $url = $this->brandUrl($b, $platform, $campaign);
            $pat = '/(?<![\p{L}\d\/\[\]])' . preg_quote($name, '/') . '(?![\p{L}\d\]\)])/u';
            foreach ($lines as $idx => $line) {
                if ($line === '' || $line[0] === '#') {
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

    private function buildToc(string $body): string
    {
        if (!preg_match_all('/^##\s+(.+?)\s*$/mu', $body, $m) || count($m[1]) < 2) {
            return '';
        }

        return "## Содержание\n\n" . implode("\n", array_map(static fn($h) => '- ' . $h, $m[1]));
    }

    private function buildCta(string $platform, string $campaign): string
    {
        $url = sprintf('%s/ru/brands?utm_source=%s&utm_medium=article&utm_campaign=cta-%s',
            self::SITE_BASE, rawurlencode($platform), rawurlencode($campaign));

        return "## С чего начать\n\n"
            . "Сравните бренды, ассортимент и цены в каталоге WEARBASE — карточки, контакты и ссылки на официальные магазины.\n\n"
            . "[Открыть каталог брендов →]({$url})";
    }

    /** @param Brand[] $brands */
    private function buildJsonLd(string $title, array $brands, string $persona, string $platform, string $campaign): string
    {
        $items = [];
        foreach ($brands as $i => $b) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $b->getTitle(),
                'url'      => $this->brandUrl($b, $platform, $campaign),
            ];
        }
        $data = [
            '@context' => 'https://schema.org',
            '@graph'   => [[
                '@type'      => 'Article',
                'headline'   => $title,
                'author'     => ['@type' => 'Person', 'name' => mb_convert_case($persona, MB_CASE_TITLE, 'UTF-8')],
                'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => $items],
            ]],
        ];

        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function renderDocument(string $title, string $persona, string $platform, string $toc, string $body, string $cta, string $jsonLd): string
    {
        $tocSection = $toc === '' ? '' : "{$toc}\n\n";

        return <<<MD
        <!-- площадка: {$platform} · автор-персона: {$persona} · формат: гид -->

        # {$title}

        {$tocSection}{$body}

        {$cta}

        ---

        <script type="application/ld+json">
        {$jsonLd}
        </script>
        MD;
    }

    /** Кириллица → latin-slug для имени файла (москва→moskva, санкт→sankt). */
    private function translit(string $s): string
    {
        $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
        $s = strtr(mb_strtolower(trim($s), 'UTF-8'), $map);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    }

    private function saveDocument(string $outDir, BrandStyle $style, ?string $city, string $platform, string $document, SymfonyStyle $io): string
    {
        if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            $io->warning("Не удалось создать {$outDir} — пишу в текущую папку.");
            $outDir = '.';
        }
        $citySlug = $city ? '-' . $this->translit((string) $city) : '';
        $file = sprintf('%s/guide-%s%s-%s.md', rtrim($outDir, '/'), $style->getSlug(), $citySlug, $platform);
        file_put_contents($file, $document);

        return $file;
    }
}
