<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandSourceUrl;
use App\Repository\BrandKeywordRepository;
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
 * Классификатор ПРОИСХОЖДЕНИЯ бренда (docs/foreign_brands_policy.md).
 * WEARBASE — каталог РОССИЙСКИХ брендов; импорты лидов (NJAL, РБК, парсинг)
 * затянули глобальные марки (Nike, Chanel, Zara…). Иностранные бренды не
 * публикуем никогда: страница Nike ломает позиционирование, тонкие автостраницы
 * по чужим мега-брендам — триггер scaled content / Баден-Бадена, плюс
 * претензионный риск. Команда пишет origin_status ∈ {ru, foreign, unknown}:
 *   - foreign гейтит конвейер и дрип-публикацию (гейт ставит другой код —
 *     PipelineQueueRepository / publish-tick; эта команда ТОЛЬКО классифицирует);
 *   - сомнение НИКОГДА не превращается в foreign автоматически (fail-safe:
 *     российская диаспора и бренды с иностранными именами → unknown → ручной review).
 *
 * Вердикт: фаст-путь по списку всемирно известных марок (без LLM), остальное —
 * мультисигнально: country + TLD собственного сайта + RAG-описание + Wordstat-фразы
 * → локальная ollama; foreign только при ≥2 согласных сигналах (LLM + country).
 * НЕ деструктивно, кроме явного --set; status/publish_pending не трогает.
 *
 *   php -d memory_limit=512M bin/console app:brand:origin-check 6000 --no-debug
 */
#[AsCommand(
    name: 'app:brand:origin-check',
    description: 'Классифицировать происхождение брендов (ru/foreign/unknown) — foreign гейтит публикацию',
)]
class OriginCheckCommand extends Command
{
    private const BATCH = 20;

    /** Всемирно известные глобальные марки — фаст-путь без LLM (точное совпадение LOWER(title)). */
    private const GLOBAL_BRANDS = [
        'nike', 'adidas', 'puma', 'reebok', 'new balance', 'asics',
        'chanel', 'dior', 'gucci', 'prada', 'hermes', 'louis vuitton',
        'versace', 'armani', 'giorgio armani', 'balenciaga', 'burberry',
        'fendi', 'valentino', 'saint laurent', 'cartier', 'tissot',
        'rolex', 'omega', 'casio', 'seiko', 'zara', 'h&m', 'uniqlo',
        'mango', 'tommy hilfiger', 'calvin klein', 'lacoste', 'ralph lauren',
        'hugo boss', 'boss', 'guess', 'diesel', 'stone island', 'moncler',
        'the north face', 'columbia', 'converse', 'vans', 'fila', 'kappa',
        'umbro', 'crocs', 'timberland', 'ecco', 'geox', 'swarovski',
        'pandora', 'bvlgari', 'tiffany', 'montblanc', 'longines',
        "victoria's secret", 'massimo dutti', 'bershka', 'stradivarius', 'oysho',
    ];

    /** Варианты написания России в brand.country (поле свободного ввода). */
    private const RU_COUNTRY = ['россия', 'russia', 'российская федерация', 'russian federation', 'рф'];

    private const SYSTEM_PROMPT = <<<TXT
        Ты — классификатор каталога WEARBASE (каталог РОССИЙСКИХ брендов одежды).
        Определи происхождение бренда по предоставленным сигналам.
        ru      — бренд основан и/или базируется в России (штаб-квартира, «основан в Москве…»),
                  ВКЛЮЧАЯ российские бренды с иностранными/латинскими названиями.
        foreign — зарубежный бренд: основан и базируется вне России (США, Европа, Азия и т.д.).
        unknown — сигналов недостаточно, происхождение достоверно не определяется.
        Название на латинице САМО ПО СЕБЕ не признак иностранного бренда. Сомневаешься — unknown.
        Ответь СТРОГО одним JSON-объектом без пояснений и без markdown:
        {"verdict":"ru","reason":"<до 120 символов>"} либо {"verdict":"foreign","reason":"<до 120 символов>"}
        либо {"verdict":"unknown","reason":"<до 120 символов>"}.
        TXT;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LlmService $llm,
        private readonly BrandKeywordRepository $keywords,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('limit', InputArgument::OPTIONAL, 'Сколько брендов проверить за прогон', 100)
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Какие бренды брать: new|active|all', 'all')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Только один бренд по ID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Не записывать — только показать вердикты')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Перепроверить уже классифицированные')
            ->addOption('set', null, InputOption::VALUE_REQUIRED, 'Ручной вердикт (только с --id): ru|foreign|unknown|reset');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // --- Ручной вердикт по итогам ревью ---
        if (($set = $input->getOption('set')) !== null) {
            return $this->manualSet($io, (int) $input->getOption('id'), (string) $set);
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $brands = $this->select($input);

        if ($brands === []) {
            $io->success('Нечего классифицировать (всё проверено либо очередь пуста).');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Классификация происхождения: %d брендов%s', count($brands), $dryRun ? ' (dry-run)' : ''));

        $ru = $foreignFast = $foreignLlm = $unknown = $skipped = 0;
        $activeForeign = []; // живые бренды, помеченные foreign — инцидент для ручного решения
        $i = 0;

        foreach ($brands as $brand) {
            [$verdict, $reason] = $this->classify($brand);

            if ($verdict === null) {
                $skipped++;
                $io->writeln(sprintf('  <comment>?  %s</comment> — LLM не дал валидный JSON, пропуск', $brand->getSlug()));
                continue;
            }

            $tag = match ($verdict) {
                'ru'      => '<fg=green>ru     </>',
                'foreign' => '<fg=red>foreign</>',
                default   => '<fg=yellow>unknown</>',
            };
            $io->writeln(sprintf('  %s %s — %s', $tag, $brand->getSlug(), $reason));

            match ($verdict) {
                'ru'      => $ru++,
                'foreign' => str_starts_with((string) $reason, 'fast-path') ? $foreignFast++ : $foreignLlm++,
                default   => $unknown++,
            };

            if ($verdict === 'foreign' && $brand->getStatus() === \Nevinny\AdminCoreBundle\Enum\Statuses::Active) {
                $activeForeign[] = $brand->getSlug() . ' — ' . $reason;
            }

            if (!$dryRun) {
                $brand->markOrigin($verdict, $reason, new \DateTime());
                if (++$i % self::BATCH === 0) {
                    $this->em->flush();
                }
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->newLine();
        $io->table(
            ['ru', 'foreign (fast-path)', 'foreign (llm+сигнал)', 'unknown', 'пропущено'],
            [[$ru, $foreignFast, $foreignLlm, $unknown, $skipped]],
        );

        if ($activeForeign !== []) {
            $io->warning(sprintf('%d ОПУБЛИКОВАННЫХ (active) брендов помечены foreign — нужно ручное решение (disabled/оставить):', count($activeForeign)));
            $io->listing($activeForeign);
        }

        return Command::SUCCESS;
    }

    /**
     * Вердикт по бренду: фаст-путь по списку глобальных марок, иначе сигналы + LLM.
     *
     * Комбинация fail-safe: foreign — только при ≥2 согласных сигналах
     * (LLM=foreign И country явно иностранная). LLM=foreign без подтверждения
     * country → unknown (ручной review). LLM=ru → ru. Сомнение никогда не
     * превращается в foreign автоматически.
     *
     * @return array{0: 'ru'|'foreign'|'unknown'|null, 1: string|null} verdict + reason (null = парсинг провалился)
     */
    private function classify(Brand $brand): array
    {
        if (in_array(mb_strtolower(trim((string) $brand->getTitle())), self::GLOBAL_BRANDS, true)) {
            return ['foreign', 'fast-path: всемирно известный глобальный бренд'];
        }

        $countrySignal = $this->countrySignal($brand->getCountry()); // 'ru'|'foreign'|null
        $host          = $this->ownSiteHost($brand);

        $kw = $this->keywords->findByBrandRanked($brand, 10);
        try {
            $raw = $this->llm->generate($this->buildPrompt($brand, $host, $kw), self::SYSTEM_PROMPT, local: true, think: false, maxTokens: 200);
        } catch (\RuntimeException) {
            return [null, null]; // сервер недоступен/таймаут — не помечаем, повторим позже
        }

        [$llmVerdict, $llmReason] = $this->parseVerdict($raw);
        if ($llmVerdict === null) {
            return [null, null];
        }

        if ($llmVerdict === 'ru') {
            $prefix = $countrySignal === 'ru' ? 'llm+country' : 'llm';
            return ['ru', $prefix . ': ' . $llmReason];
        }

        if ($llmVerdict === 'foreign') {
            if ($countrySignal === 'foreign') {
                return ['foreign', 'llm+country: ' . $llmReason];
            }
            // Одиночный сигнал LLM (country пусто/ru, TLD не считаем) — fail-safe в review
            return ['unknown', 'llm-only(foreign): ' . $llmReason];
        }

        return ['unknown', 'llm: ' . $llmReason];
    }

    /** brand.country → 'ru' | 'foreign' | null (пусто). Поле свободного ввода, заполнено у ~8%. */
    private function countrySignal(?string $country): ?string
    {
        $c = mb_strtolower(trim((string) $country));
        if ($c === '') {
            return null;
        }

        return in_array($c, self::RU_COUNTRY, true) ? 'ru' : 'foreign';
    }

    /** Хост собственного сайта из brand_source_url (own_site, лучший по relevance), null если нет. */
    private function ownSiteHost(Brand $brand): ?string
    {
        $url = $this->em->getConnection()->fetchOne(
            'SELECT url FROM brand_source_url WHERE brand_id = ? AND source_type = ? ORDER BY relevance_score DESC LIMIT 1',
            [$brand->getId(), BrandSourceUrl::TYPE_OWN_SITE],
        );
        if (!is_string($url) || $url === '') {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower(preg_replace('/^www\./', '', $host)) : null;
    }

    /** TLD .ru/.рф/.su → сигнал ru. Иностранный TLD сигналом foreign НЕ считаем (у российских брендов бывают .com). */
    private function isRuTld(string $host): bool
    {
        foreach (['.ru', '.su', '.рф', '.xn--p1ai'] as $tld) {
            if (str_ends_with($host, $tld)) {
                return true;
            }
        }

        return false;
    }

    /** @param \App\Entity\BrandKeyword[] $topKeywords */
    private function buildPrompt(Brand $brand, ?string $host, array $topKeywords): string
    {
        $lines = ['Бренд: ' . $brand->getTitle()];
        if (($country = trim((string) $brand->getCountry())) !== '') {
            $lines[] = 'Страна (поле каталога): ' . $country;
        }
        if ($host !== null) {
            $lines[] = 'Собственный сайт: ' . $host . ($this->isRuTld($host) ? ' (российская доменная зона)' : '');
        }
        if (($anons = trim((string) ($brand->getAnons() ?? $brand->getTagline()))) !== '') {
            $lines[] = $anons;
        }
        if (($desc = trim(strip_tags((string) $brand->getDescription()))) !== '') {
            $lines[] = 'Описание: ' . mb_substr($desc, 0, 1500);
        }
        if ($topKeywords !== []) {
            $lines[] = 'Поисковые запросы: ' . implode(', ', array_map(fn ($k) => $k->getKeyword(), $topKeywords));
        }

        return implode("\n", $lines);
    }

    /** @return array{0: 'ru'|'foreign'|'unknown'|null, 1: string|null} */
    private function parseVerdict(string $raw): array
    {
        if (!preg_match('/\{.*\}/s', $raw, $m)) {
            return [null, null];
        }
        $data = json_decode($m[0], true);
        $verdict = is_array($data) ? ($data['verdict'] ?? null) : null;
        if (!in_array($verdict, ['ru', 'foreign', 'unknown'], true)) {
            return [null, null];
        }
        $reason = is_array($data) ? trim((string) ($data['reason'] ?? '')) : '';

        return [$verdict, $reason !== '' ? $reason : $verdict];
    }

    /** @return Brand[] */
    private function select(InputInterface $input): array
    {
        if (($id = $input->getOption('id')) !== null) {
            $brand = $this->em->find(Brand::class, (int) $id);
            return $brand !== null ? [$brand] : [];
        }

        $statuses = match ($input->getOption('status')) {
            'new'    => ['new'],
            'active' => ['active'],
            default  => ['new', 'active'],
        };
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        // active первыми (живые/в индексе), внутри — по убыванию суммарного спроса
        // (опубликованные глобальные марки ловим раньше).
        $sql = "SELECT b.id FROM brand b
                LEFT JOIN brand_keyword k ON k.brand_id = b.id
                WHERE b.status IN ($placeholders)"
            . ((bool) $input->getOption('force') ? '' : ' AND b.origin_status IS NULL')
            . ' GROUP BY b.id
                ORDER BY (b.status = \'active\') DESC, COALESCE(SUM(k.monthly_shows), 0) DESC
                LIMIT ' . max(1, (int) $input->getArgument('limit'));

        $ids = $this->em->getConnection()->fetchFirstColumn($sql, $statuses);

        return array_values(array_filter(array_map(fn ($id) => $this->em->find(Brand::class, (int) $id), $ids)));
    }

    private function manualSet(SymfonyStyle $io, int $id, string $action): int
    {
        $brand = $this->em->find(Brand::class, $id);
        if ($brand === null) {
            $io->error("Бренд #$id не найден.");
            return Command::FAILURE;
        }

        match ($action) {
            'ru', 'foreign', 'unknown' => $brand->markOrigin($action, 'manual', new \DateTime()),
            'reset'                    => $brand->markOrigin(null, null, null),
            default                    => null,
        };
        if (!in_array($action, ['ru', 'foreign', 'unknown', 'reset'], true)) {
            $io->error("Неизвестное действие '$action' (ru|foreign|unknown|reset).");
            return Command::FAILURE;
        }

        $this->em->flush();
        $io->success(sprintf('Бренд «%s» (#%d): origin=%s.', $brand->getSlug(), $id, $action));

        return Command::SUCCESS;
    }
}
