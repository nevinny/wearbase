<?php

namespace App\Command;

use App\Entity\AioRemediation;
use App\Notification\AdminNotifier;
use App\Repository\AioRemediationRepository;
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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Первый срез авто-ремедиации AIO-утечки (docs/seo_sitewide_backlog.md HIGH#2,
 * docs/drmax_seo_2026_digest.md §5). Цикл: detect → map → generate → gate → persist
 * (→ apply — ТОЛЬКО вручную по кнопке в Telegram, см. TelegramController::handleCallback
 * «aioapply:»/«aioreject:»). Эта команда НИКОГДА не пишет в brand_faq — только
 * кандидатов в aio_remediation со status=pending.
 *
 * - detect: gsc_query_stats, группа brand_entity («чей бренд»), impressions≥min-impr, clicks=0.
 * - map: вычленяем имя бренда из запроса (маркеры группы brand_entity), матчим на
 *   опубликованный активный НЕ-foreign бренд (BrandRepository::findOneActiveByTitle);
 *   если у бренда уже есть FAQ с entity-вопросом — пропуск (уже отвечено).
 * - generate: BrandRagService::retrieve() + описание бренда → факты; LlmService::
 *   generateBrandFaq() на один запрос → одна Q/A-пара (нет фактов/ответа — grounded-skip).
 * - gate: ContentValidator::isRefusal() на ответе (тот же grounded-гейт, что и в
 *   остальных генерациях контента проекта).
 * - persist: dedup по (brand, kind=faq) — существующий pending обновляется, не дублируется.
 *
 * Только Mac (dev, чтение gsc_query_stats + локальная ollama/RAG).
 *
 *   php bin/console app:seo:aio-remediate --limit=10 --min-impr=8
 *   php bin/console app:seo:aio-remediate --notify
 */
#[AsCommand(
    name: 'app:seo:aio-remediate',
    description: 'AIO-утечка («чей бренд», gsc_query_stats) → grounded FAQ-кандидат в aio_remediation (apply — только по кнопке в Telegram)',
)]
class AioRemediateCommand extends Command
{
    /** Сколько строк gsc_query_stats перебрать в поисках $limit валидных кандидатов. */
    private const POOL_LIMIT = 300;

    private int $candidates      = 0;
    private int $unmatched       = 0;
    private int $foreignSkipped  = 0;
    private int $alreadyAnswered = 0;
    private int $noFacts         = 0;
    private int $llmSkipped      = 0;
    private int $gateRejected    = 0;

    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        private readonly AioQueryClassifier $classifier,
        private readonly BrandRepository $brandRepo,
        private readonly BrandFaqRepository $faqRepo,
        private readonly AioRemediationRepository $remediationRepo,
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
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Макс. кандидатов за прогон', '10')
            ->addOption('min-impr', null, InputOption::VALUE_REQUIRED, 'Порог показов для AIO-утечки', '8')
            ->addOption('notify', null, InputOption::VALUE_NONE, 'Слать в Telegram (по умолчанию — только консоль)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $limit   = max(1, (int) $input->getOption('limit'));
        $minImpr = max(1, (int) $input->getOption('min-impr'));
        $notify  = (bool) $input->getOption('notify');

        $io->title('SEO · AIO-ремедиация («чей бренд») — grounded-кандидаты в FAQ');

        $rows = $this->fetchLeakQueries($minImpr);
        if ($rows === []) {
            $io->warning('gsc_query_stats пуста / нет утечек группы brand_entity — нечего делать.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Запросов-утечек (brand_entity) в пуле: %d, цель: %d кандидатов', count($rows), $limit));

        $results = []; // персистнутые/обновлённые в этом прогоне — для консоли и TG
        foreach ($rows as $row) {
            if (count($results) >= $limit) {
                break;
            }
            $candidate = $this->processQuery((string) $row['query'], (int) $row['impressions']);
            if ($candidate !== null) {
                $results[] = $candidate;
            }
        }

        $this->printResults($io, $results);

        if ($notify && $this->adminNotifier->isEnabled()) {
            $this->notify($results);
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
                continue; // первый срез — только «чей бренд»-формат
            }
            $out[] = ['query' => $q, 'impressions' => (int) $r['impressions']];
        }

        return $out;
    }

    /**
     * map → generate → gate → persist для одного запроса.
     * null — пропущен (соответствующий счётчик уже увеличен).
     *
     * @return ?array{id:int,brand:string,query:string,impressions:int,question:string,answer:string}
     */
    private function processQuery(string $query, int $impressions): ?array
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
        if ($this->faqRepo->hasBrandEntityQuestion($brand)) {
            $this->alreadyAnswered++;
            return null;
        }

        // Факты: описание бренда (он-пейдж истина) + RAG-корпус, если прошёл gate качества.
        $facts = trim((string) $brand->getDescription());
        $ragContext = $this->rag->retrieve($brand)['context'];
        if ($ragContext !== null) {
            $facts .= "\n\nДополнительные факты из источников:\n" . $ragContext;
        }
        if ($facts === '') {
            $this->noFacts++;
            return null;
        }

        $qa = $this->llm->generateBrandFaq((string) $brand->getTitle(), [$query], $facts, $brand->getCity());
        if ($qa === []) {
            // Модель честно не нашла в фактах ответа на этот запрос — grounded-skip, не ошибка.
            $this->llmSkipped++;
            return null;
        }
        $pair = $qa[0];

        if ($this->validator->isRefusal($pair['answer'])) {
            $this->gateRejected++;
            return null;
        }

        // Dedup: (brand, kind) уже pending — обновляем вместо нового ряда.
        $remediation = $this->remediationRepo->findOnePending($brand, AioRemediation::KIND_FAQ)
            ?? new AioRemediation();
        $remediation
            ->setBrand($brand)
            ->setQuery($query)
            ->setKind(AioRemediation::KIND_FAQ)
            ->setProposedQuestion($pair['question'])
            ->setProposedAnswer($pair['answer'])
            ->setStatus(AioRemediation::STATUS_PENDING);

        $this->em->persist($remediation);
        $this->em->flush();

        $this->candidates++;

        return [
            'id'          => $remediation->getId(),
            'brand'       => (string) $brand->getTitle(),
            'query'       => $query,
            'impressions' => $impressions,
            'question'    => $pair['question'],
            'answer'      => $pair['answer'],
        ];
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

    /** @param array<int,array{id:int,brand:string,query:string,impressions:int,question:string,answer:string}> $results */
    private function printResults(SymfonyStyle $io, array $results): void
    {
        if ($results !== []) {
            $io->table(
                ['ID', 'Бренд', 'Запрос', 'Показы', 'Вопрос'],
                array_map(
                    static fn(array $r) => [$r['id'], $r['brand'], mb_substr($r['query'], 0, 40), $r['impressions'], mb_substr($r['question'], 0, 60)],
                    $results,
                ),
            );
        }

        $io->newLine();
        $io->table(['Результат', 'Кол-во'], [
            ['Кандидатов сохранено (pending)', $this->candidates],
            ['Не смэтчено на бренд',           $this->unmatched],
            ['Foreign — пропущено',            $this->foreignSkipped],
            ['Уже отвечено (FAQ есть)',        $this->alreadyAnswered],
            ['Нет фактов',                     $this->noFacts],
            ['LLM не нашла ответ в фактах',    $this->llmSkipped],
            ['Отклонено гейтом (refusal)',     $this->gateRejected],
        ]);
    }

    /** @param array<int,array{id:int,brand:string,query:string,impressions:int,question:string,answer:string}> $results */
    private function notify(array $results): void
    {
        if ($results === []) {
            $this->adminNotifier->send('🔎 <b>AIO-ремедиация</b>: новых кандидатов нет.');
            return;
        }

        $this->adminNotifier->send(sprintf(
            "🔎 <b>AIO-ремедиация</b>: %d кандидат(ов) FAQ по утечке «чей бренд».\nПроверьте карточки ниже — применение только по кнопке.",
            count($results),
        ));

        foreach ($results as $r) {
            $html = sprintf(
                "<b>%s</b>\nЗапрос: %s (показы: %d)\n\n<b>Q:</b> %s\n<b>A:</b> %s",
                htmlspecialchars($r['brand']),
                htmlspecialchars($r['query']),
                $r['impressions'],
                htmlspecialchars($r['question']),
                htmlspecialchars(mb_substr($r['answer'], 0, 500)),
            );
            $this->adminNotifier->sendWithButtons($html, [
                ['text' => '✅ Применить',  'data' => 'aioapply:' . $r['id']],
                ['text' => '❌ Отклонить', 'data' => 'aioreject:' . $r['id']],
            ]);
        }
    }
}
