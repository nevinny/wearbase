<?php

namespace App\Command;

use App\Service\ContentValidator;
use App\Service\LlmService;
use App\Service\MarketplaceExitContext;
use App\Service\Seo\SpellChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Серия «уход с маркетплейсов / прямые продажи» (docs/marketplace_exit_content.md):
 * по кураторским углам (config/content/marketplace_angles.yaml) генерирует редакционные
 * B2B-статьи. Все цифры — ТОЛЬКО из проверенных фактов (MarketplaceExitContext, type=fact;
 * opinion физически отфильтрован). Wildberries/Ozon/Яндекс.Маркет — действующие компании,
 * упоминаются строго нейтрально; прямые продажи бренд→покупатель без комиссии с продаж —
 * мягкое позиционирование WEARBASE.
 *
 * На каждый угол — ДВЕ копии: site (canonical для блога, {out}/blog/mp-{slug}-site.md) и
 * dzen ({out}/dzen/mp-{slug}-dzen.md, без таблиц). Общая база имени mp-{slug} + суффиксы
 * -site/-dzen — под ArticleDistributionAttacher::topicKey.
 *
 *   php bin/console app:seo:marketplace-article --dry-run          # план по углам
 *   php bin/console app:seo:marketplace-article --angle=fz-289     # один угол
 *   php bin/console app:seo:marketplace-article --limit=2
 */
#[AsCommand(
    name: 'app:seo:marketplace-article',
    description: 'SEO: редакционная статья по углу «уход с маркетплейсов / прямые продажи» (grounded, curated-факты)',
)]
class MarketplaceArticleCommand extends Command
{
    private const SITE_BASE = 'https://wearbase.ru';
    private const ANGLES_FILE = '/config/content/marketplace_angles.yaml';
    private const MIN_BODY_WORDS = 600;
    private const MAX_GEN_ATTEMPTS = 3;
    private const PLATFORMS = ['site', 'dzen'];

    private const PLATFORM_TONES = [
        'site' => 'редакционная статья каталога WEARBASE для собственного блога: информативно, по-деловому экспертно, без рекламного давления',
        'dzen' => 'статья для Яндекс.Дзена: информативно и экспертно, но живым языком, с пользой и вовлечением читателя',
    ];

    public function __construct(
        private readonly LlmService             $llm,
        private readonly ContentValidator       $validator,
        private readonly MarketplaceExitContext $facts,
        private readonly SpellChecker           $spellChecker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('angle',   null, InputOption::VALUE_REQUIRED, 'Обработать один угол по slug')
            ->addOption('limit',   null, InputOption::VALUE_REQUIRED, 'Максимум углов за прогон')
            ->addOption('out',     null, InputOption::VALUE_REQUIRED, 'Базовая папка ({out}/blog + {out}/dzen)', 'var/seo')
            ->addOption('force',   null, InputOption::VALUE_NONE,     'Сохранять даже при провале quality-gate (с предупреждением)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Показать план (slug, заголовок, кол-во фактов) без генерации')
            ->addOption('persona', null, InputOption::VALUE_REQUIRED, 'Переопределить персону для всех углов (по умолчанию — из yaml)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $angleSlug     = $input->getOption('angle');
        $limit         = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;
        $outDir        = rtrim((string) $input->getOption('out'), '/');
        $force         = (bool) $input->getOption('force');
        $dryRun        = (bool) $input->getOption('dry-run');
        $personaOverride = $input->getOption('persona') !== null ? trim((string) $input->getOption('persona')) : null;

        $angles = $this->loadAngles($io);
        if ($angles === null) {
            return Command::FAILURE;
        }
        if ($angleSlug !== null) {
            $angles = array_values(array_filter($angles, static fn(array $a) => $a['slug'] === $angleSlug));
            if ($angles === []) {
                $io->error("Угол «{$angleSlug}» не найден в " . self::ANGLES_FILE);
                return Command::FAILURE;
            }
        }
        if ($limit !== null) {
            $angles = array_slice($angles, 0, $limit);
        }

        $io->title('SEO · серия «уход с маркетплейсов / прямые продажи»' . ($dryRun ? ' — DRY-RUN' : ''));

        $ok = 0;
        $rejected = 0;
        $skipped = 0;

        foreach ($angles as $cfg) {
            $slug = (string) $cfg['slug'];
            $io->section("Угол: {$slug}");

            $factBlock = $this->facts->factsForAngle($cfg['fact_refs']);
            $factCount = $factBlock === '' ? 0 : substr_count($factBlock, "\n") + 1;
            $persona   = $personaOverride ?? (string) $cfg['persona'];

            $io->definitionList(
                ['Заголовок' => (string) $cfg['title']],
                ['Фактов'    => $factCount . ($cfg['fact_refs'] === [] ? ' (тематическая, без цифр-якорей)' : '')],
                ['Персона'   => $persona],
            );

            // Resume-скип: обе копии уже на диске → угол готов (--force пересоздаёт).
            if (!$force && !$dryRun
                && is_file("{$outDir}/blog/mp-{$slug}-site.md")
                && is_file("{$outDir}/dzen/mp-{$slug}-dzen.md")) {
                $io->text('  обе копии уже существуют — пропуск (пересоздать: --force).');
                $skipped++;
                continue;
            }

            if ($dryRun) {
                continue;
            }

            foreach (self::PLATFORMS as $platform) {
                if ($this->generateForPlatform($cfg, $factBlock, $persona, $platform, $outDir, $force, $io)) {
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
            ['Пропущено углов',   $skipped],
        ]);

        if ($dryRun) {
            return Command::SUCCESS;
        }

        return $ok > 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Генерация одной копии (площадка site|dzen) с self-heal, гейтами и сохранением.
     *
     * @param array{slug:string,title:string,brief:string,fact_refs:array,persona:string} $cfg
     */
    private function generateForPlatform(
        array $cfg,
        string $factBlock,
        string $persona,
        string $platform,
        string $outDir,
        bool $force,
        SymfonyStyle $io,
    ): bool {
        $tone = self::PLATFORM_TONES[$platform];
        $io->text(sprintf('Генерация · %s · %s', $platform, $persona));

        // Self-heal: до MAX_GEN_ATTEMPTS попыток, температура 0.7→0.6→0.5. Hard-issues
        // блокируют приём; numericWhitelist — soft (только в fixHint, не hard-fail).
        $temps = [0.7, 0.6, 0.5];
        $fixHint = null;
        $body = null;
        $hard = ['пусто'];
        for ($att = 0; $att < self::MAX_GEN_ATTEMPTS; $att++) {
            try {
                $raw = $this->llm->generateMarketplaceArticle(
                    (string) $cfg['title'], (string) $cfg['brief'], $factBlock, $persona, $tone,
                    $fixHint, $temps[$att] ?? 0.5, noTables: $platform === 'dzen',
                );
            } catch (\Throwable $e) {
                $hard = ['LLM ошибка: ' . mb_substr($e->getMessage(), 0, 80)];
                $io->text(sprintf('  попытка %d/%d → LLM недоступна, пауза 15с…', $att + 1, self::MAX_GEN_ATTEMPTS));
                sleep(15);
                continue;
            }
            if (trim($raw) === '') {
                $hard = ['LLM вернула пусто'];
                continue;
            }
            $raw  = $this->softenCliches($raw);
            $hard = $this->qualityGate($raw);
            // Известный глюк gemma4:26b («му»/«ло»/«лан» внутри слова) — проверяем только
            // когда остальной hard-гейт уже чист (иначе fixHint и так регенерит).
            if ($hard === []) {
                $hard = $this->glitchGate($raw);
            }
            $soft = $this->numericWhitelist($raw, $factBlock);
            $body = $raw;
            if ($hard === [] && $soft === []) {
                break;
            }
            $fixHint = implode('; ', array_merge($hard, $soft));
            $io->text(sprintf('  попытка %d/%d → gate: %s', $att + 1, self::MAX_GEN_ATTEMPTS, $fixHint));
        }

        // Приём/отбраковка — ТОЛЬКО по hard-issues (numericWhitelist никогда не hard-fail).
        if ($body === null || ($hard !== [] && !$force)) {
            $io->warning('  Отбраковано после ' . self::MAX_GEN_ATTEMPTS . ' попыток: ' . implode('; ', $hard));
            return false;
        }
        if ($hard !== [] && $force) {
            $io->note('  quality-gate (проигнорирован --force): ' . implode('; ', $hard));
        }

        // Корректура — один раз на принятом черновике (+ повторный softenCliches).
        $body = $this->softenCliches($this->applyProofread($body, $io));

        $title    = (string) $cfg['title'];
        $campaign = sprintf('mp-%s-%s', $cfg['slug'], $platform);

        $toc      = $this->buildToc($body);
        $cta      = $this->buildCta($platform, $campaign);
        $document = $this->renderDocument($title, $persona, $platform, $toc, $body, $cta, '');

        $path = $this->saveDocument($outDir, (string) $cfg['slug'], $platform, $document, $io);
        $io->success("Сохранено: {$path}");

        return true;
    }

    /**
     * Кураторский список углов из config/content/marketplace_angles.yaml.
     *
     * @return array<int,array{slug:string,title:string,brief:string,fact_refs:array,persona:string}>|null
     */
    private function loadAngles(SymfonyStyle $io): ?array
    {
        $file = \dirname(__DIR__, 2) . self::ANGLES_FILE;
        if (!is_file($file)) {
            $io->error("Нет файла углов: {$file}");
            return null;
        }

        $data = Yaml::parseFile($file);
        $out = [];
        foreach (($data['angles'] ?? []) as $a) {
            $slug = trim((string) ($a['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $out[] = [
                'slug'      => $slug,
                'title'     => trim((string) ($a['title'] ?? '')),
                'brief'     => trim((string) ($a['brief'] ?? '')),
                'fact_refs' => is_array($a['fact_refs'] ?? null) ? array_values($a['fact_refs']) : [],
                'persona'   => trim((string) ($a['persona'] ?? 'аналитик e-commerce')),
            ];
        }
        if ($out === []) {
            $io->error("Файл углов пуст: {$file}");
            return null;
        }

        return $out;
    }

    /**
     * Базовый quality-gate (без бренд-семантики) + legal-denylist. Hard-issues:
     * отказ модели, markdown-обёртка, мало слов, несамодостаточный лид, AI-штампы,
     * оценочные обвинения.
     *
     * @return string[]
     */
    private function qualityGate(string $body): array
    {
        $issues = [];

        if ($this->validator->isRefusal($body)) {
            $issues[] = 'модель вернула отказ (нет фактов)';
        }

        if (str_contains($body, '```')) {
            $issues[] = 'в теле осталась markdown-обёртка ```';
        }

        $words = (int) preg_match_all('/\p{L}+/u', $body);
        if ($words < self::MIN_BODY_WORDS) {
            $issues[] = sprintf('мало слов: %d < %d', $words, self::MIN_BODY_WORDS);
        }

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
        $issues = array_merge($issues, $this->legalDenylist($body));

        return $issues;
    }

    /**
     * Гейт известного глюка gemma4:26b — слог «му»/«ло»/«лан» вклинивается внутрь слова
     * (docs/tasktracker.md, баг от 2026-07-07). Двухфакторно: ContentValidator::
     * findGlitchCandidateWords() — дёшево, но шумно (мидворд «ло»/«му» — обычная русская
     * фонетика: «около», «смута»); подтверждаем только словом, которое сам Yandex Speller
     * не узнаёт, — так реальные слова не флагуются, а «мессмуджеры»/«СДмуК» — флагуются.
     *
     * @return string[]
     */
    private function glitchGate(string $body): array
    {
        $candidates = $this->validator->findGlitchCandidateWords($body);
        if ($candidates === []) {
            return [];
        }

        $flaggedWords = array_map(
            static fn(array $f) => mb_strtolower((string) $f['word'], 'UTF-8'),
            $this->spellChecker->proofread($body)['flags'],
        );

        $confirmed = array_values(array_intersect(
            array_map(static fn(string $w) => mb_strtolower($w, 'UTF-8'), $candidates),
            $flaggedWords,
        ));

        return $confirmed === []
            ? []
            : [sprintf('похоже на глюк модели (слог «му»/«ло»/«лан» внутри слова, слово не по словарю): %s', implode(', ', $confirmed))];
    }

    /**
     * Legal-denylist: оценочные обвинения в адрес действующих площадок — безусловный брак
     * (нейтральность обязательна: Wildberries/Ozon/Яндекс.Маркет — работающие компании).
     *
     * @return string[]
     */
    private function legalDenylist(string $body): array
    {
        if (preg_match('/кидают|обман|мошенн|грабят|ворует|наживает|врут|развод/iu', $body, $m)) {
            return ["legal-denylist: оценочное обвинение «{$m[0]}»"];
        }

        return [];
    }

    /**
     * numeric-whitelist (SOFT, только в fixHint — не hard-fail): каждая денежная/процентная
     * цифра тела должна встречаться в блоке фактов. Годы/даты без единицы игнорируем
     * (регэксп ловит только %, п.п., ₽, руб, млрд, трлн, юан).
     *
     * @return string[]
     */
    private function numericWhitelist(string $body, string $factBlock): array
    {
        if (!preg_match_all('/(\d[\d.,]*)\s*(%|п\.п\.|₽|руб|млрд|трлн|юан)/iu', $body, $mm, PREG_SET_ORDER)) {
            return [];
        }

        $factsNorm = $this->normalizeNumbers($factBlock);
        $seen = [];
        $issues = [];
        foreach ($mm as $m) {
            $num = rtrim($m[1], '.,');
            if ($num === '' || isset($seen[$num])) {
                continue;
            }
            $seen[$num] = true;
            if (mb_strpos($factsNorm, $this->normalizeNumbers($num)) === false) {
                $issues[] = sprintf('цифра «%s %s» не подтверждена блоком фактов', $num, $m[2]);
            }
        }

        return $issues;
    }

    /** Нормализация чисел для сравнения: запятая → точка (43,5 == 43.5), схлопнуть пробелы. */
    private function normalizeNumbers(string $s): string
    {
        return str_replace(',', '.', $s);
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

    /**
     * Корректорский LLM-проход: чинит опечатки/грамматику до сборки.
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

        // proofread — САМ отдельный вызов gemma4:26b → сам может внести известный
        // глюк («му»/«ло»/«лан» внутрь слова) в уже принятый чистый черновик; этот
        // проход не проходит self-heal retry (только один шанс), поэтому при глюке —
        // не регенерим, откатываемся к дочищенному оригиналу.
        $glitch = $this->glitchGate($clean);
        if ($glitch !== []) {
            $io->note('  proofread: ' . implode('; ', $glitch) . ' — оставлен оригинал (без корректуры)');
            return $body;
        }

        return $clean;
    }

    /** Мягкая правка упрямого клише «уникальн-» (gemma вставляет вопреки промпту). */
    private function softenCliches(string $body): string
    {
        return (string) preg_replace_callback('/уникальн/iu', static function (array $m): string {
            $first = mb_substr($m[0], 0, 1, 'UTF-8');
            return mb_strtoupper($first, 'UTF-8') === $first ? 'Самобытн' : 'самобытн';
        }, $body);
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

    /** CTA-блок на каталог брендов WEARBASE. */
    private function buildCta(string $platform, string $campaign): string
    {
        $url = $platform === 'site'
            ? self::SITE_BASE . '/ru/brands'
            : sprintf('%s/ru/brands?utm_source=%s&utm_medium=article&utm_campaign=cta-%s',
                self::SITE_BASE, rawurlencode($platform), rawurlencode($campaign));

        return "## С чего начать\n\n"
            . "Посмотрите российские бренды одежды в каталоге WEARBASE — там карточки, контакты "
            . "и прямые ссылки на магазины брендов.\n\n"
            . "[Смотреть бренды в каталоге →]({$url})";
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
     * Сохранение: общая база имени mp-{slug} + суффикс -{platform} — под
     * ArticleDistributionAttacher::topicKey (site-копия → {out}/blog, dzen-копия → {out}/dzen).
     */
    private function saveDocument(string $outDir, string $slug, string $platform, string $document, SymfonyStyle $io): string
    {
        $dir = $outDir . '/' . ($platform === 'site' ? 'blog' : $platform);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $io->warning("Не удалось создать {$dir} — пишу в текущую папку.");
            $dir = '.';
        }

        $file = sprintf('%s/mp-%s-%s.md', rtrim($dir, '/'), $slug, $platform);
        file_put_contents($file, $document);

        return $file;
    }
}
