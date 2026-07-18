<?php

namespace App\Command;

use App\Entity\AioRemediation;
use App\Entity\Brand;
use App\Entity\BrandAttribute;
use App\Entity\BrandContentRevision;
use App\Entity\BrandDatapoint;
use App\Entity\BrandFaq;
use App\Entity\BrandRagPipeline;
use App\Notification\AdminNotifier;
use App\Repository\BrandContentRevisionRepository;
use App\Repository\BrandFaqRepository;
use App\Repository\BrandRepository;
use App\Service\BrandRagService;
use App\Service\ContentValidator;
use App\Service\LlmService;
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
 * данные на Mac). Теперь: detect → map → ГИБРИД по типу бренда → generate/gate
 * → auto-apply, БЕЗ ручного подтверждения.
 *
 * - detect: gsc_query_stats, группа brand_entity («чей бренд») через
 *   AioQueryClassifier, impressions≥min-impr, clicks=0, сорт по показам DESC.
 * - map: вычленяем имя бренда из запроса (маркеры группы brand_entity), матчим
 *   на опубликованный активный НЕ-foreign бренд (BrandRepository::findOneActiveByTitle,
 *   precision>recall). Не смэтчено / foreign — счётчик, skip.
 * - ГИБРИД по type бренда (owner-решение 2026-07-19, обоснование — DrMax: не
 *   переписывать уже ранжирующееся, тонкое — генерить, богатое — добавлять
 *   layered видимый блок, а не переписывать):
 *
 *   THIN (описание < GenerateBrandContentCommand::MIN_REAL_DESCRIPTION_CHARS):
 *   без изменений — тот же путь, что был раньше. skip-гейт: активная ревизия
 *   ещё PENDING (эксперимент в полёте — не стартуем новый поверх неизмеренного).
 *   Generate+gate ПЕРЕИСПОЛЬЗУЕТ GenerateBrandContentCommand — она инжектится
 *   как сервис и вызывается через run(--id=N --grounded-only[--dry-run]), а не
 *   дублирует RAG/quality-gate/versioner логику. Успех = дельта
 *   counters()['processed'] до/после. Auto-apply = generate-content сам вызывает
 *   ensureBaseline()/record(); здесь только дописываем note='aio:{query}' на
 *   только что созданную активную ревизию + аудит-строка (kind=description).
 *
 *   RICH (описание ≥ порога — переписывать рискованно, ранжирование уже есть):
 *   additive gap-блок, НЕ трогаем description/BrandContentVersioner.
 *   1. Skip «уже покрыто», если на карточке уже рендерится детерминированный
 *      HIGH#5-блок «Что за бренд X?» (tailwind/brand/show.html.twig, ТО ЖЕ
 *      условие: city ИЛИ foundingYear ИЛИ видимый category-атрибут) ИЛИ у
 *      бренда уже есть entity-FAQ (BrandFaqRepository::hasBrandEntityQuestion) —
 *      не плодим дубль.
 *   2. Иначе — grounded entity-FAQ: факты = описание + RAG-контекст
 *      (BrandRagService, ТОТ ЖЕ способ сборки фактов, что GenerateBrandFaqCommand).
 *      Нет фактов → skip. Один Q&A через LlmService::generateBrandFaq с
 *      вопросом-затравкой «что за бренд/чей бренд», берём первую пару.
 *      Гейт ContentValidator::isRefusal на ответ (+ сам факт, что LLM вернул
 *      пустой список = grounded-отказ) → не прошёл → тот же skip-счётчик, что
 *      у thin-гейта (единая строка сводки «нет фактов/гейт»).
 *   3. --apply: persist BrandFaq(brand, question, answer, position=след.,
 *      source=llm), бампаем BrandRagPipeline::contentChangedAt (та же механика
 *      свежести, что GenerateBrandFaqCommand при FAQ_DONE) — НО faqStatus НЕ
 *      трогаем: batch app:brand:faq (Wordstat-фразы, findForFaq требует
 *      faqStatus IS NULL) должен остаться доступен этому бренду отдельно.
 *      Аудит-строка aio_remediation(kind=faq, status=applied). --dry-run
 *      (дефолт) — только превью.
 *
 * ⚠️ Честно: rich-ветка (доп. FAQ) НЕ измеряется и НЕ откатывается revision-
 * loop'ом EvaluateExperimentsCommand — это additive-контент (не замена
 * ранжирующегося текста), grounded+gated, риск низкий. Полноценный замер
 * win/loss для FAQ — будущая доработка (не в этом срезе).
 *
 * Только Mac (dev, чтение gsc_query_stats + локальная ollama/RAG).
 *
 *   php bin/console app:seo:aio-remediate --limit=10 --min-impr=8            # dry-run (по умолчанию)
 *   php bin/console app:seo:aio-remediate --apply --limit=10 --notify
 */
#[AsCommand(
    name: 'app:seo:aio-remediate',
    description: 'AIO-утечка («чей бренд», gsc_query_stats) → гибрид thin-generate/rich-gap-FAQ closed-loop auto-apply',
)]
class AioRemediateCommand extends Command
{
    /** Сколько строк gsc_query_stats перебрать в поисках $limit валидных кандидатов. */
    private const POOL_LIMIT = 300;

    private int $appliedThin       = 0; // thin: описание сгенерировано (или было бы в dry-run)
    private int $appliedFaq        = 0; // rich: gap-FAQ добавлен (или было бы в dry-run)
    private int $unmatched         = 0;
    private int $foreignSkipped    = 0;
    private int $pendingExperiment = 0; // thin: активная ревизия ещё не измерена
    private int $alreadyCovered    = 0; // rich: HIGH#5-блок уже рендерится ИЛИ entity-FAQ уже есть
    private int $gateSkipped       = 0; // thin: generate-content не дал processed; rich: нет фактов/isRefusal

    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        private readonly AioQueryClassifier $classifier,
        private readonly BrandRepository $brandRepo,
        private readonly BrandContentRevisionRepository $revisionRepo,
        private readonly BrandFaqRepository $faqRepo,
        private readonly GenerateBrandContentCommand $generateCommand,
        private readonly BrandRagService $rag,
        private readonly LlmService $llm,
        private readonly ContentValidator $validator,
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
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Реально применить (записать brand.*/versioner/faq/audit)')
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

        $io->title('SEO · AIO-ремедиация («чей бренд») — гибрид thin-generate/rich-gap-FAQ');
        if ($dryRun) {
            $io->note('dry-run: превью, без записи brand.*/versioner/faq/audit (нужен --apply)');
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
     * map → гибрид-роутинг по содержательности описания (thin/rich) → соответствующая ветка.
     * null — пропущен (соответствующий счётчик уже увеличен).
     *
     * @return ?array{brand:string,query:string,impressions:int,kind:string}
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

        // Гибрид-развилка: тот же порог, что и generate-content, — не выдумываем новый
        // критерий «содержательное описание».
        $existingDescription = trim((string) $brand->getDescription());
        if (mb_strlen($existingDescription) < GenerateBrandContentCommand::MIN_REAL_DESCRIPTION_CHARS) {
            return $this->processThin($brand, $query, $impressions, $dryRun);
        }

        return $this->processRich($brand, $query, $impressions, $dryRun);
    }

    /**
     * THIN: описание тонкое — переиспользуем полный путь GenerateBrandContentCommand
     * (grounded RAG, refusal/QA/near-dup гейты, versioner.record при успехе).
     *
     * @return ?array{brand:string,query:string,impressions:int,kind:string}
     */
    private function processThin(Brand $brand, string $query, int $impressions, bool $dryRun): ?array
    {
        // skip-гейт: эксперимент в полёте — не стартуем новый поверх неизмеренного.
        $active = $this->revisionRepo->findActive($brand);
        if ($active !== null && $active->getVerdict() === BrandContentRevision::VERDICT_PENDING) {
            $this->pendingExperiment++;
            return null;
        }

        $brandId = $brand->getId();
        $brandTitle = (string) $brand->getTitle();

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

        $this->appliedThin++;

        return ['brand' => $brandTitle, 'query' => $query, 'impressions' => $impressions, 'kind' => 'thin'];
    }

    /**
     * RICH: описание уже содержательное (переписывать рискованно — DrMax) — additive
     * gap-блок: один grounded entity-FAQ («что за бренд/чей бренд») поверх страницы.
     *
     * @return ?array{brand:string,query:string,impressions:int,kind:string}
     */
    private function processRich(Brand $brand, string $query, int $impressions, bool $dryRun): ?array
    {
        if ($this->isEntityCoverageAlreadyPresent($brand)) {
            $this->alreadyCovered++;
            return null;
        }

        $brandId = $brand->getId();
        $brandTitle = (string) $brand->getTitle();

        // Факты: описание бренда (он-пейдж истина) + RAG-корпус, если прошёл gate —
        // ТОТ ЖЕ способ сборки, что GenerateBrandFaqCommand.
        $facts = trim((string) $brand->getDescription());
        $ragContext = $this->rag->retrieve($brand)['context'];
        if ($ragContext !== null) {
            $facts .= "\n\nДополнительные факты из источников:\n" . $ragContext;
        }
        if ($facts === '') {
            $this->gateSkipped++;
            return null;
        }

        // Вопрос-затравка entity-интента («что за бренд / чей бренд») — не Wordstat-фразы,
        // нам нужен ровно один детерминированный Q&A под HIGH#2-утечку, а не полный FAQ.
        $seeds = [
            sprintf('что за бренд %s?', $brandTitle),
            sprintf('%s чей бренд', $brandTitle),
        ];
        try {
            $qa = $this->llm->generateBrandFaq($brandTitle, $seeds, $facts, $brand->getCity());
        } catch (\Throwable) {
            // Сетевая/LLM-ошибка не должна ронять весь batch-прогон (как в GenerateBrandFaqCommand).
            $this->gateSkipped++;
            $this->em->clear();
            return null;
        }
        if ($qa === []) {
            // Модель сама grounded-отказалась (нет фактов под вопрос) — тот же счётчик, что thin-гейт.
            $this->gateSkipped++;
            return null;
        }

        $pair = $qa[0];
        if ($this->validator->isRefusal($pair['answer'])) {
            $this->gateSkipped++;
            return null;
        }

        if (!$dryRun) {
            $freshBrand = $this->em->find(Brand::class, $brandId) ?? $brand;
            $position = count($this->faqRepo->findByBrandOrdered($freshBrand));

            $this->em->persist((new BrandFaq())
                ->setBrand($freshBrand)
                ->setQuestion($pair['question'])
                ->setAnswer($pair['answer'])
                ->setPosition($position)
                ->setSource(BrandFaq::SOURCE_LLM));

            // Свежесть для push-конвейера на прод — та же механика, что GenerateBrandFaqCommand
            // при FAQ_DONE. faqStatus НЕ трогаем: batch app:brand:faq (Wordstat-фразы,
            // findForFaq требует faqStatus IS NULL) должен остаться доступен этому бренду.
            $this->em->getRepository(BrandRagPipeline::class)
                ->getOrCreate($freshBrand)
                ->setContentChangedAt(new \DateTime());

            $this->em->persist((new AioRemediation())
                ->setBrand($freshBrand)
                ->setQuery($query)
                ->setKind(AioRemediation::KIND_FAQ)
                ->setProposedQuestion(mb_substr($pair['question'], 0, 255))
                ->setProposedAnswer($pair['answer'])
                ->setStatus(AioRemediation::STATUS_APPLIED)
                ->setAppliedAt(new \DateTime()));

            $this->em->flush();
            $this->em->clear();
        }

        $this->appliedFaq++;

        return ['brand' => $brandTitle, 'query' => $query, 'impressions' => $impressions, 'kind' => 'faq'];
    }

    /**
     * «Уже покрыто» для rich-ветки: (а) на карточке уже рендерится детерминированный
     * HIGH#5-блок «Что за бренд X?» — ТО ЖЕ условие, что tailwind/brand/show.html.twig
     * (_short_has_bio = brand.city or brand.foundingYear or attrGroups['category']);
     * ИЛИ (б) у бренда уже есть entity-FAQ.
     */
    private function isEntityCoverageAlreadyPresent(Brand $brand): bool
    {
        if ($brand->getCity() || $brand->getFoundingYear()) {
            return true;
        }
        if ($this->hasVisibleCategoryAttribute($brand)) {
            return true;
        }

        return $this->faqRepo->hasBrandEntityQuestion($brand);
    }

    /**
     * attrGroups['category'] в show.html.twig отфильтрован от краудсорс-скрытых точек
     * (BrandDatapoint state=hidden) — воспроизводим тот же фильтр, иначе «уже покрыто»
     * ложно сработает на атрибуте, который на странице фактически не виден.
     */
    private function hasVisibleCategoryAttribute(Brand $brand): bool
    {
        $hiddenAttrIds = [];
        foreach ($this->em->getRepository(BrandDatapoint::class)->findHiddenByBrand($brand) as $dp) {
            if ($dp->getTargetType() === BrandDatapoint::TYPE_ATTRIBUTE && $dp->getField() === 'value') {
                $hiddenAttrIds[$dp->getTargetId()] = true;
            }
        }

        foreach ($this->em->getRepository(BrandAttribute::class)->findBy(['brand' => $brand, 'name' => BrandAttribute::NAME_CATEGORY]) as $attr) {
            if (!isset($hiddenAttrIds[$attr->getId()])) {
                return true;
            }
        }

        return false;
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

    /** @param array<int,array{brand:string,query:string,impressions:int,kind:string}> $results */
    private function printResults(SymfonyStyle $io, array $results): void
    {
        if ($results !== []) {
            $io->table(
                ['Бренд', 'Запрос', 'Показы', 'Ветка'],
                array_map(
                    static fn(array $r) => [$r['brand'], mb_substr($r['query'], 0, 40), $r['impressions'], $r['kind']],
                    $results,
                ),
            );
        }

        $io->newLine();
        $io->table(['Результат', 'Кол-во'], [
            ['Thin — описание сгенерировано',            $this->appliedThin],
            ['Rich — gap-FAQ добавлен',                   $this->appliedFaq],
            ['Rich — уже покрыто (блок/FAQ)',             $this->alreadyCovered],
            ['Нет фактов / не прошёл гейт',               $this->gateSkipped],
            ['Не смэтчено на бренд',                      $this->unmatched],
            ['Foreign — пропущено',                       $this->foreignSkipped],
            ['Thin — эксперимент в полёте (PENDING)',     $this->pendingExperiment],
        ]);
    }

    /** @param array<int,array{brand:string,query:string,impressions:int,kind:string}> $results */
    private function notify(array $results, bool $dryRun): void
    {
        $verb = $dryRun ? 'было бы применено (dry-run)' : 'применено';

        if ($results === []) {
            $this->adminNotifier->send(sprintf('🔎 <b>AIO-ремедиация</b>: %s 0.', $verb));
            return;
        }

        $lines = array_map(
            static fn(array $r) => sprintf(
                '%s ← %s [%s]',
                htmlspecialchars($r['brand']),
                htmlspecialchars($r['query']),
                $r['kind'] === 'faq' ? 'rich-FAQ' : 'thin',
            ),
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
