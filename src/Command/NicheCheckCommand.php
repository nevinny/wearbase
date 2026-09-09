<?php

namespace App\Command;

use App\Entity\Brand;
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
 * Классификатор ниши бренда. WEARBASE — каталог МОДЫ (одежда/обувь/аксессуары)
 * + косметика/уход/парфюм. Импорт лидов и сбор Wordstat затянули чужие бренды
 * (зубная паста apadent, аптека «Апрель», пылесосы, смартфоны, матрасы) — у них
 * реальный, но НЕНИШЕВЫЙ спрос. Команда помечает их niche_status='off':
 *   - off гейтит конвейер (PipelineQueueRepository) и дрип-публикацию (publish-tick);
 *   - для уже опубликованных (active) — только пометка + reason для ручного ревью
 *     (НЕ снимаем автоматически: возможны false-positive LLM).
 *
 * Вердикт: фаст-путь по однозначным маркерам в keywords (без LLM), остальное —
 * локальная ollama (как generate-content). НЕ деструктивно, кроме явного --set.
 *
 *   php -d memory_limit=512M bin/console app:brand:niche-check 7000 --no-debug
 */
#[AsCommand(
    name: 'app:brand:niche-check',
    description: 'Классифицировать бренды на принадлежность нише (мода+красота) — off гейтит конвейер',
)]
class NicheCheckCommand extends Command
{
    private const BATCH = 20;

    /** Однозначно НЕ мода и НЕ косметика — фаст-путь без LLM (без «крем/паста/шампунь» — они beauty-неоднозначны). */
    private const OFF_MARKERS = [
        'пылесос', 'смартфон', 'ноутбук', 'телевизор', 'холодильник', 'кондиционер',
        'матрас', 'автомобил', 'запчаст', 'унитаз', 'смесител', 'ламинат', 'плитк',
        'дрель', 'шуруповерт', 'насос', 'бойлер', 'бензопил', 'аптека', 'корм для',
        'сухой корм', 'удобрен', 'саженц', 'рассад',
    ];

    private const SYSTEM_PROMPT = <<<TXT
        Ты — классификатор каталога WEARBASE. WEARBASE — каталог брендов МОДЫ: одежда, обувь,
        сумки, аксессуары, ювелирка и бижутерия, а также КОСМЕТИКА, уход за кожей и волосами,
        парфюмерия. Определи, относится ли бренд к этой нише.
        IN  — мода (одежда/обувь/аксессуары) ИЛИ косметика/уход/парфюм.
        OFF — всё остальное: аптечные сети, лекарства и БАД, гигиена полости рта (зубные пасты и
        щётки), бытовая техника и электроника, мебель и матрасы, авто и запчасти, продукты и
        напитки, стройматериалы, товары для дома/сада, зоотовары.
        Ответь СТРОГО одним JSON-объектом без пояснений и без markdown:
        {"verdict":"in","reason":"<до 120 символов>"} либо {"verdict":"off","reason":"<до 120 символов>"}.
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
            ->addOption('set', null, InputOption::VALUE_REQUIRED, 'Ручное действие (только с --id): in|off|closed|reopen|delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // --- Ручное действие по итогам ревью ---
        if (($set = $input->getOption('set')) !== null) {
            return $this->manualSet($io, (int) $input->getOption('id'), (string) $set);
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $force  = (bool) $input->getOption('force');
        $brands = $this->select($input);

        if ($brands === []) {
            $io->success('Нечего классифицировать (всё проверено либо очередь пуста).');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Классификация ниши: %d брендов%s', count($brands), $dryRun ? ' (dry-run)' : ''));

        $in = $offMarker = $offLlm = $skipped = 0;
        $activeOff = []; // живые бренды, помеченные off — инцидент для ручного решения
        $i = 0;

        foreach ($brands as $brand) {
            [$verdict, $reason] = $this->classify($brand);

            if ($verdict === null) {
                $skipped++;
                $io->writeln(sprintf('  <comment>?  %s</comment> — LLM не дал валидный JSON, пропуск', $brand->getSlug()));
                continue;
            }

            $tag = $verdict === 'off' ? '<fg=red>off</>' : '<fg=green>in </>';
            $io->writeln(sprintf('  %s %s — %s', $tag, $brand->getSlug(), $reason));

            if ($verdict === 'off') {
                str_starts_with((string) $reason, 'marker:') ? $offMarker++ : $offLlm++;
                if ($brand->getStatus() === \Nevinny\AdminCoreBundle\Enum\Statuses::Active) {
                    $activeOff[] = $brand->getSlug() . ' — ' . $reason;
                }
            } else {
                $in++;
            }

            if (!$dryRun) {
                $brand->markNiche($verdict, $reason, new \DateTime());
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
            ['in', 'off (маркер)', 'off (LLM)', 'пропущено'],
            [[$in, $offMarker, $offLlm, $skipped]],
        );

        if ($activeOff !== []) {
            $io->warning(sprintf('%d ОПУБЛИКОВАННЫХ (active) брендов помечены off — нужно ручное решение (--set=delete / оставить):', count($activeOff)));
            $io->listing($activeOff);
        }

        return Command::SUCCESS;
    }

    /**
     * Вердикт по бренду: фаст-путь по маркерам, иначе LLM.
     * @return array{0: 'in'|'off'|null, 1: string|null} verdict + reason (null verdict = парсинг провалился)
     */
    private function classify(Brand $brand): array
    {
        $kw = $this->keywords->findByBrandRanked($brand, 20);
        $haystack = mb_strtolower($brand->getTitle() . ' ' . implode(' ', array_map(fn ($k) => $k->getKeyword(), $kw)));

        foreach (self::OFF_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return ['off', 'marker:' . $marker];
            }
        }

        $prompt = $this->buildPrompt($brand, array_slice($kw, 0, 5));
        try {
            $raw = $this->llm->generate($prompt, self::SYSTEM_PROMPT, local: true, think: false, maxTokens: 200);
        } catch (\RuntimeException) {
            return [null, null]; // сервер недоступен/таймаут — не помечаем, повторим позже
        }

        return $this->parseVerdict($raw);
    }

    private function buildPrompt(Brand $brand, array $topKeywords): string
    {
        $lines = ['Бренд: ' . $brand->getTitle()];
        $anons = trim((string) ($brand->getAnons() ?? $brand->getTagline()));
        if ($anons !== '') {
            $lines[] = $anons;
        }
        $desc = trim(strip_tags((string) $brand->getDescription()));
        if ($desc !== '') {
            $lines[] = 'Описание: ' . mb_substr($desc, 0, 400);
        }
        if ($topKeywords !== []) {
            $lines[] = 'Поисковые запросы: ' . implode(', ', array_map(fn ($k) => $k->getKeyword(), $topKeywords));
        }

        // Свежий лид: описания нет, ключевиков нет — LLM судит по одному названию и системно
        // валит мелкие незнакомые бренды («не относится к категориям моды»). На выборке из
        // 505 лидов ProVybor так отсеялись 202 бренда, у которых на площадке лежат десятки
        // товаров одежды. Поэтому при отсутствии описания подкладываем факты из корпуса.
        if ($desc === '' && $anons === '' && ($facts = $this->corpusFacts($brand)) !== null) {
            $lines[] = 'Факты из источников: ' . $facts;
        }

        return implode("\n", $lines);
    }

    /** Выжимка из собранного корпуса бренда (самые релевантные документы), до 900 знаков. */
    private function corpusFacts(Brand $brand): ?string
    {
        $texts = $this->em->getConnection()->fetchFirstColumn(
            'SELECT clean_text FROM brand_source_document
             WHERE brand_id = ? AND deleted_at IS NULL AND char_count > 0
             ORDER BY relevance_score DESC, char_count DESC
             LIMIT 2',
            [$brand->getId()]
        );

        if ($texts === []) {
            return null;
        }

        $joined = trim(preg_replace('/\s+/u', ' ', implode(' ', $texts)) ?? '');

        return $joined !== '' ? mb_substr($joined, 0, 900) : null;
    }

    /** @return array{0: 'in'|'off'|null, 1: string|null} */
    private function parseVerdict(string $raw): array
    {
        if (!preg_match('/\{.*\}/s', $raw, $m)) {
            return [null, null];
        }
        $data = json_decode($m[0], true);
        $verdict = is_array($data) ? ($data['verdict'] ?? null) : null;
        if (!in_array($verdict, ['in', 'off'], true)) {
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
        // (высокотрафиковый шум вроде «аптека апрель» ловим раньше).
        $sql = "SELECT b.id FROM brand b
                LEFT JOIN brand_keyword k ON k.brand_id = b.id
                WHERE b.status IN ($placeholders)"
            . ((bool) $input->getOption('force') ? '' : ' AND b.niche_checked_at IS NULL')
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
            'in', 'off' => $brand->markNiche($action, 'manual', new \DateTime()),
            'closed'    => $brand->close(),
            'reopen'    => $brand->reopen(),
            'delete'    => $brand->softDelete(),
            default     => null,
        };
        if (!in_array($action, ['in', 'off', 'closed', 'reopen', 'delete'], true)) {
            $io->error("Неизвестное действие '$action' (in|off|closed|reopen|delete).");
            return Command::FAILURE;
        }

        $this->em->flush();
        $io->success(sprintf('Бренд «%s» (#%d): %s.', $brand->getSlug(), $id, $action));

        return Command::SUCCESS;
    }
}
