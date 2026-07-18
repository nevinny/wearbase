<?php

namespace App\Command;

use App\Entity\AioRemediation;
use App\Entity\Brand;
use App\Entity\BrandContentRevision;
use App\Notification\AdminNotifier;
use App\Repository\BrandContentRevisionRepository;
use App\Repository\BrandRepository;
use App\Service\Seo\AioQueryClassifier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Closed-loop авто-ремедиация AIO-утечки (docs/seo_sitewide_backlog.md HIGH#2,
 * docs/drmax_seo_2026_digest.md §5). Раньше срез генерил FAQ-кандидатов и слал
 * TG-кнопки «Применить/Отклонить» — сломано кросс-хостово (вебхук на проде,
 * данные на Mac). Теперь: detect → map → skip-гейты → generate+gate+auto-apply
 * через BrandContentVersioner, БЕЗ ручного подтверждения. Замер win/loss и
 * авто-rollback делает EvaluateExperimentsCommand — здесь только старт эксперимента.
 *
 * - detect: gsc_query_stats, группа brand_entity («чей бренд») через
 *   AioQueryClassifier, impressions≥min-impr, clicks=0, сорт по показам DESC.
 * - map: вычленяем имя бренда из запроса (маркеры группы brand_entity), матчим
 *   на опубликованный активный НЕ-foreign бренд (BrandRepository::findOneActiveByTitle,
 *   precision>recall). Не смэтчено / foreign — счётчик, skip.
 * - skip-гейты: (1) активная ревизия ещё PENDING (эксперимент в полёте — не
 *   стартуем новый поверх неизмеренного); (2) описание уже содержательное —
 *   ТОТ ЖЕ порог, что и generate-content (GenerateBrandContentCommand::
 *   MIN_REAL_DESCRIPTION_CHARS), не выдумываем новый.
 * - generate+gate+record: ПЕРЕИСПОЛЬЗУЕТ путь GenerateBrandContentCommand —
 *   эта команда инжектится как сервис и вызывается через run(--id=N
 *   --grounded-only[--dry-run]), а не дублирует RAG/quality-gate/versioner
 *   логику. Успех определяется дельтой counters()['processed'] до/после (нет
 *   фактов/не прошёл гейт → processed не растёт → skip+счётчик, без разбора причины —
 *   она уже в логе generate-content). Auto-apply = generate-content сам вызывает
 *   ensureBaseline()/record() при успехе; здесь только дописываем note='aio:{query}'
 *   на только что созданную активную ревизию для трассировки повода.
 * - audit: аудит-лог применённого — новая строка в aio_remediation
 *   (kind=description, status=applied, query, brand, applied_at,
 *   proposed_answer=сгенерированное description). Только в apply-режиме.
 *
 * Только Mac (dev, чтение gsc_query_stats + локальная ollama/RAG через generate-content).
 *
 *   php bin/console app:seo:aio-remediate --limit=10 --min-impr=8            # dry-run (по умолчанию)
 *   php bin/console app:seo:aio-remediate --apply --limit=10 --notify
 */
#[AsCommand(
    name: 'app:seo:aio-remediate',
    description: 'AIO-утечка («чей бренд», gsc_query_stats) → closed-loop auto-apply grounded description через BrandContentVersioner',
)]
class AioRemediateCommand extends Command
{
    /** Сколько строк gsc_query_stats перебрать в поисках $limit валидных кандидатов. */
    private const POOL_LIMIT = 300;

    private int $applied            = 0; // применено (или было бы применено в dry-run)
    private int $unmatched          = 0;
    private int $foreignSkipped     = 0;
    private int $pendingExperiment  = 0; // активная ревизия ещё не измерена
    private int $alreadyStrong      = 0; // описание уже содержательное (MIN_REAL_DESCRIPTION_CHARS)
    private int $gateSkipped        = 0; // generate-content не дал processed (нет фактов/QA/near-dup/ошибка)

    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        private readonly AioQueryClassifier $classifier,
        private readonly BrandRepository $brandRepo,
        private readonly BrandContentRevisionRepository $revisionRepo,
        private readonly GenerateBrandContentCommand $generateCommand,
        private readonly AdminNotifier $adminNotifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Макс. брендов за прогон', '10')
            ->addOption('min-impr', null, InputOption::VALUE_REQUIRED, 'Порог показов для AIO-утечки', '8')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Превью (по умолчанию и так dry-run — флаг для явности в логах/кроне)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Реально применить (записать brand.*/versioner/audit)')
            ->addOption('notify', null, InputOption::VALUE_NONE, 'Слать информационную сводку в Telegram (текст, без кнопок)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $limit   = max(1, (int) $input->getOption('limit'));
        $minImpr = max(1, (int) $input->getOption('min-impr'));
        $apply   = (bool) $input->getOption('apply');
        $dryRun  = !$apply; // dry-run — дефолт; --dry-run — тот же смысл явно
        $notify  = (bool) $input->getOption('notify');

        $io->title('SEO · AIO-ремедиация («чей бренд») — closed-loop auto-apply');
        if ($dryRun) {
            $io->note('dry-run: превью, без записи brand.*/versioner/audit (нужен --apply)');
        }

        $rows = $this->fetchLeakQueries($minImpr);
        if ($rows === []) {
            $io->warning('gsc_query_stats пуста / нет утечек группы brand_entity — нечего делать.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Запросов-утечек (brand_entity) в пуле: %d, цель: %d брендов', count($rows), $limit));

        $results = []; // обработанные в этом прогоне — для консоли и TG
        foreach ($rows as $row) {
            if (count($results) >= $limit) {
                break;
            }
            $candidate = $this->processQuery((string) $row['query'], (int) $row['impressions'], $dryRun);
            if ($candidate !== null) {
                $results[] = $candidate;
            }
        }

        $this->printResults($io, $results);

        if ($notify && $this->adminNotifier->isEnabled()) {
            $this->notify($results, $dryRun);
        }

        return Command::SUCCESS;
    }

    /**
     * detect: AIO-утечка (показы есть, кликов нет) в формате brand_entity («чей бренд»).
     * @return list<array{query:string,impressions:int}>
     */
    private function fetchLeakQueries(int $minImpr): array
    {
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT query, SUM(impressions) impressions, SUM(clicks) clicks
                 FROM gsc_query_stats GROUP BY query
                 HAVING impressions >= ? AND clicks = 0
                 ORDER BY impressions DESC LIMIT ' . self::POOL_LIMIT,
                [$minImpr],
            );
        } catch (\Throwable) {
            return []; // таблицы нет / не синкали app:gsc:sync
        }

        $out = [];
        foreach ($rows as $r) {
            $q = (string) $r['query'];
            if ($this->classifier->classify($q)['name'] !== 'brand_entity') {
                continue; // тот же первый срез — только «чей бренд»-формат
            }
            $out[] = ['query' => $q, 'impressions' => (int) $r['impressions']];
        }

        return $out;
    }

    /**
     * map → skip-гейты → generate+gate (reuse GenerateBrandContentCommand) → auto-apply → audit.
     * null — пропущен (соответствующий счётчик уже увеличен).
     *
     * @return ?array{brand:string,query:string,impressions:int}
     */
    private function processQuery(string $query, int $impressions, bool $dryRun): ?array
    {
        $name = $this->extractBrandName($query);
        if ($name === null) {
            $this->unmatched++;
            return null;
        }

        $brand = $this->brandRepo->findOneActiveByTitle($name);
        if ($brand === null) {
            $this->unmatched++;
            return null;
        }
        if ($brand->isForeignOrigin()) {
            $this->foreignSkipped++;
            return null;
        }

        // skip-гейт 1: эксперимент в полёте — не стартуем новый поверх неизмеренного.
        $active = $this->revisionRepo->findActive($brand);
        if ($active !== null && $active->getVerdict() === BrandContentRevision::VERDICT_PENDING) {
            $this->pendingExperiment++;
            return null;
        }

        // skip-гейт 2: описание уже содержательное — тот же порог, что в generate-content
        // (не выдумываем новый критерий «сильное/свежее»).
        $existingDescription = trim((string) $brand->getDescription());
        if (mb_strlen($existingDescription) >= GenerateBrandContentCommand::MIN_REAL_DESCRIPTION_CHARS) {
            $this->alreadyStrong++;
            return null;
        }

        $brandId = $brand->getId();
        $brandTitle = (string) $brand->getTitle();

        // Generate+gate: тот же путь, что batch/--id прогон generate-content (grounded RAG,
        // refusal/QA/near-dup гейты, versioner.record при успехе) — reuse, не дублируем.
        $genOptions = [
            '--id'            => (string) $brandId,
            '--grounded-only' => true,
        ];
        if ($dryRun) {
            $genOptions['--dry-run'] = true;
        }
        $before = $this->generateCommand->counters();
        $this->generateCommand->run(new ArrayInput($genOptions), new NullOutput());
        $after = $this->generateCommand->counters();

        if ($after['processed'] <= $before['processed']) {
            // Нет фактов / не прошёл refusal-QA-near-dup гейт / ошибка LLM — единый счётчик,
            // причина уже видна в логе generate-content (deferred/review/generate_failed).
            $this->gateSkipped++;
            return null;
        }

        if (!$dryRun) {
            // generate-content сам сделал flush()+clear() при успехе — перечитываем свежую
            // активную ревизию, чтобы дописать note (повод) и залогировать в aio_remediation.
            $freshBrand = $this->em->find(Brand::class, $brandId);
            $rev = $freshBrand !== null ? $this->revisionRepo->findActive($freshBrand) : null;
            if ($rev !== null) {
                $rev->setNote('aio:' . mb_substr($query, 0, 240));
            }
            $this->em->persist((new AioRemediation())
                ->setBrand($freshBrand)
                ->setQuery($query)
                ->setKind(AioRemediation::KIND_DESCRIPTION)
                ->setProposedQuestion(mb_substr($query, 0, 255))
                ->setProposedAnswer($rev?->getDescription() ?? '')
                ->setStatus(AioRemediation::STATUS_APPLIED)
                ->setAppliedAt(new \DateTime()));
            $this->em->flush();
            $this->em->clear();
        }

        $this->applied++;

        return ['brand' => $brandTitle, 'query' => $query, 'impressions' => $impressions];
    }

    /**
     * Вычленяет имя бренда из запроса, срезая маркеры интента группы brand_entity —
     * тот же regex, что классифицирует AioQueryClassifier (единый источник правды,
     * без дублирования шаблона).
     */
    private function extractBrandName(string $query): ?string
    {
        $pattern = null;
        foreach ($this->classifier->groups() as $g) {
            if ($g['name'] === 'brand_entity') {
                $pattern = $g['pattern'];
                break;
            }
        }
        if ($pattern === null) {
            return null;
        }

        $name = (string) preg_replace($pattern, ' ', $query);
        $name = trim($name, " \t\n\r\0\x0B-—,.:;?!\"'«»");
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return $name !== '' ? $name : null;
    }

    /** @param array<int,array{brand:string,query:string,impressions:int}> $results */
    private function printResults(SymfonyStyle $io, array $results): void
    {
        if ($results !== []) {
            $io->table(
                ['Бренд', 'Запрос', 'Показы'],
                array_map(
                    static fn(array $r) => [$r['brand'], mb_substr($r['query'], 0, 40), $r['impressions']],
                    $results,
                ),
            );
        }

        $io->newLine();
        $io->table(['Результат', 'Кол-во'], [
            ['Применено (auto-apply)',                  $this->applied],
            ['Не смэтчено на бренд',                     $this->unmatched],
            ['Foreign — пропущено',                      $this->foreignSkipped],
            ['Эксперимент в полёте (PENDING) — пропуск', $this->pendingExperiment],
            ['Описание уже содержательное — пропуск',    $this->alreadyStrong],
            ['Нет фактов / не прошёл гейт',               $this->gateSkipped],
        ]);
    }

    /** @param array<int,array{brand:string,query:string,impressions:int}> $results */
    private function notify(array $results, bool $dryRun): void
    {
        $verb = $dryRun ? 'было бы применено (dry-run)' : 'применено';

        if ($results === []) {
            $this->adminNotifier->send(sprintf('🔎 <b>AIO-ремедиация</b>: %s 0.', $verb));
            return;
        }

        $lines = array_map(
            static fn(array $r) => sprintf('%s ← %s', htmlspecialchars($r['brand']), htmlspecialchars($r['query'])),
            $results,
        );

        $this->adminNotifier->send(sprintf(
            "🔎 <b>AIO-ремедиация</b>: %s %d:\n%s",
            $verb,
            count($results),
            implode("\n", $lines),
        ));
    }
}
