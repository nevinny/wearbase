<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\BrandKeyword;
use App\Entity\BrandRagPipeline;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nevinny\AdminCoreBundle\Enum\Statuses;

/**
 * Очередь RAG-конвейера: stage-finder'ы (что брать на каждом этапе) + предикат
 * готовности к публикации. Вынесено из BrandRepository (SRP: тот отвечал и за
 * публичные витрины, и за конвейер, и за контакты — 560 строк, 3 ответственности).
 *
 * Простой сервис (НЕ ServiceEntityRepository): одна сущность ↔ один SER, а Brand уже
 * закреплён за BrandRepository. QueryBuilder для Brand берём через EM.
 *
 * Шардинг MOD(b.id, total)=shard даёт непересекающиеся наборы для параллельных
 * воркеров split-демона (gpu‖net) — ноль конфликтов записи. Сохранён as-is.
 */
class PipelineQueueRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    private function qb(string $alias = 'b'): QueryBuilder
    {
        return $this->em->getRepository(Brand::class)->createQueryBuilder($alias);
    }

    /** Бренды, помеченные на форс-регенерацию из loss-ветки closed-loop (priority DESC). */
    public function findRegenFlagged(int $limit, int $shard = 0, int $total = 1): array
    {
        $qb = $this->qb()
            ->innerJoin(BrandRagPipeline::class, 'p', 'WITH', 'p.brand = b')
            ->where('p.regenRequestedAt IS NOT NULL');

        return $this->finishStageQuery($qb, $limit, $shard, $total);
    }

    public function findWithDescriptionWithoutMeta(int $limit, int $shard = 0, int $total = 1): array
    {
        $qb = $this->qb()
            ->leftJoin(BrandRagPipeline::class, 'p', 'WITH', 'p.brand = b') // для сортировки по p.priority
            ->where('b.description IS NOT NULL')
            ->andWhere('b.description != :empty')
            ->andWhere('b.metaTitle IS NULL OR b.metaTitle = :empty')
            ->setParameter('empty', '');

        return $this->finishStageQuery($qb, $limit, $shard, $total);
    }

    /** Этап 1 — на скрейп: нет pipeline-строки, либо pending, либо повторяемый scrape_failed. */
    public function findForScrape(int $limit, int $shard = 0, int $total = 1, int $maxAttempts = 3): array
    {
        $qb = $this->qb()
            ->leftJoin(BrandRagPipeline::class, 'p', 'WITH', 'p.brand = b')
            // new = скрыт до дрип-публикации, но КОНВЕЙЕР его готовит (иначе очередь дрипа не дозреет)
            ->where('b.status IN (:statuses)')
            ->andWhere('p.id IS NULL OR p.status = :pending OR (p.status = :failed AND p.scrapeAttempts < :max)')
            ->setParameter('statuses', [Statuses::Active, Statuses::New])
            ->setParameter('pending', BrandRagPipeline::STATUS_PENDING)
            ->setParameter('failed', BrandRagPipeline::STATUS_SCRAPE_FAILED)
            ->setParameter('max', $maxAttempts);

        return $this->finishStageQuery($qb, $limit, $shard, $total);
    }

    /** Этап 2 — на эмбеддинг: pipeline в статусе scraped, либо повторяемый embed_failed. */
    public function findForEmbed(int $limit, int $shard = 0, int $total = 1, int $maxAttempts = 3): array
    {
        $qb = $this->qb()
            ->innerJoin(BrandRagPipeline::class, 'p', 'WITH', 'p.brand = b')
            ->where('p.status = :scraped OR (p.status = :failed AND p.embedAttempts < :max)')
            ->setParameter('scraped', BrandRagPipeline::STATUS_SCRAPED)
            ->setParameter('failed', BrandRagPipeline::STATUS_EMBED_FAILED)
            ->setParameter('max', $maxAttempts);

        return $this->finishStageQuery($qb, $limit, $shard, $total);
    }

    /** Этап 3 — на генерацию: pipeline в статусе embedded, либо повторяемый generate_failed. */
    public function findForGeneration(int $limit, int $shard = 0, int $total = 1, int $maxAttempts = 3): array
    {
        $qb = $this->qb()
            ->innerJoin(BrandRagPipeline::class, 'p', 'WITH', 'p.brand = b')
            ->where('p.status = :embedded OR (p.status = :failed AND p.generateAttempts < :max)')
            ->setParameter('embedded', BrandRagPipeline::STATUS_EMBEDDED)
            ->setParameter('failed', BrandRagPipeline::STATUS_GENERATE_FAILED)
            ->setParameter('max', $maxAttempts);

        // leastAttemptsFirst: меньше попыток — раньше (не залипаем на одних брендах при перевыборе)
        return $this->finishStageQuery($qb, $limit, $shard, $total, false, true);
    }

    /**
     * Бренды на опрос Wordstat: ещё НИ РАЗУ не опрашивали.
     * Пропускаем: у кого уже есть ключевики (k.id), и у кого keywords_status
     * проставлен (found / not_found — нишевые с 0 фраз больше не дёргаем).
     */
    public function findForKeywords(int $limit, int $shard = 0, int $total = 1): array
    {
        $qb = $this->qb()
            ->leftJoin(BrandKeyword::class, 'k', 'WITH', 'k.brand = b')
            ->leftJoin(BrandRagPipeline::class, 'p', 'WITH', 'p.brand = b')
            // new = очередь дрипа, конвейер её готовит (см. findForScrape)
            ->where('b.status IN (:statuses)')
            ->andWhere('k.id IS NULL')
            ->andWhere('p.id IS NULL OR p.keywordsStatus IS NULL')
            ->setParameter('statuses', [Statuses::Active, Statuses::New])
            ->groupBy('b.id');

        return $this->finishStageQuery($qb, $limit, $shard, $total);
    }

    /**
     * Бренды на извлечение атрибутов: есть корпус (scraped+, source_count>0),
     * атрибуты ещё не извлекали. Идёт после fetch (корпус накоплен крауля).
     */
    public function findForExtract(int $limit, int $shard = 0, int $total = 1): array
    {
        $qb = $this->qb()
            ->innerJoin(BrandRagPipeline::class, 'p', 'WITH', 'p.brand = b')
            ->where('p.sourceCount > 0')
            ->andWhere('p.attributesStatus IS NULL')
            ->andWhere('p.status IN (:done)')
            // deferred включён: атрибуты/city/год грунтуются независимо от гейта описания
            // (generate отправляет в deferred ДО extract → иначе deferred-бренды без атрибутов).
            ->setParameter('done', [BrandRagPipeline::STATUS_SCRAPED, BrandRagPipeline::STATUS_EMBEDDED, BrandRagPipeline::STATUS_DONE, BrandRagPipeline::STATUS_DEFERRED]);

        return $this->finishStageQuery($qb, $limit, $shard, $total);
    }

    /**
     * Бэкафилл полей у УЖЕ опубликованных на проде брендов (done + pushed), у которых
     * пусто city/country/foundingYear. attributesStatus у них = 'done' (findForExtract их
     * не берёт), поэтому отдельный селектор. Гонится с --fields-only (без churn атрибутов);
     * заполнение пустых полей выставит content_changed → бренд станет re-push eligible.
     */
    public function findPublishedMissingFields(int $limit, int $shard = 0, int $total = 1): array
    {
        $qb = $this->qb()
            ->innerJoin(BrandRagPipeline::class, 'p', 'WITH', 'p.brand = b')
            ->where('p.status = :done')
            ->andWhere('p.pushedAt IS NOT NULL')
            ->andWhere('p.sourceCount > 0')
            ->andWhere("((b.country IS NULL OR b.country = '') OR (b.city IS NULL OR b.city = '') OR (b.foundingYear IS NULL OR b.foundingYear = ''))")
            ->setParameter('done', BrandRagPipeline::STATUS_DONE);

        return $this->finishStageQuery($qb, $limit, $shard, $total);
    }

    /**
     * Бренды на ингест Wildberries: wb_status IS NULL, статус active/new.
     */
    public function findForWbEnrich(int $limit, int $shard = 0, int $total = 1): array
    {
        // p.id IS NOT NULL: enrich НЕ создаёт пайплайн-строки новым брендам (это работа discover) —
        // иначе getOrCreate здесь и в discover гонятся за одним brand_id → 1062 uniq_brand_rag_brand,
        // откат батча discover, net встаёт. Обогащаем только уже открытые бренды (есть строка).
        $qb = $this->qb()
            ->innerJoin(BrandRagPipeline::class, 'p', 'WITH', 'p.brand = b')
            ->where('b.status IN (:statuses)')
            ->andWhere('p.wbStatus IS NULL')
            ->setParameter('statuses', [Statuses::Active, Statuses::New]);

        return $this->finishStageQuery($qb, $limit, $shard, $total);
    }

    /**
     * Бренды на краул сайта: discover отработал, краул ещё не делали.
     * Краул раскрывает own_site → own_page (ДО полного fetch). active+new
     * (очередь дрипа тоже готовим, как discover/keywords).
     */
    public function findForCrawl(int $limit, int $shard = 0, int $total = 1): array
    {
        $qb = $this->qb()
            ->innerJoin(BrandRagPipeline::class, 'p', 'WITH', 'p.brand = b')
            ->where('b.status IN (:statuses)')
            ->andWhere('p.discoveredAt IS NOT NULL')
            ->andWhere('p.crawlStatus IS NULL')
            ->setParameter('statuses', [Statuses::Active, Statuses::New]);

        return $this->finishStageQuery($qb, $limit, $shard, $total);
    }

    /**
     * Бренды на генерацию FAQ: контент готов (done), FAQ ещё не генерили,
     * И Wordstat уже опрошен (keywordsStatus задан). Без последнего условия бренд,
     * дошедший до done раньше квотного keywords-процесса, цементируется в skipped
     * и уезжает на прод без FAQ, хотя ключевики появятся позже.
     */
    public function findForFaq(int $limit, int $shard = 0, int $total = 1): array
    {
        $qb = $this->qb()
            ->innerJoin(BrandRagPipeline::class, 'p', 'WITH', 'p.brand = b')
            ->where('p.status = :done')
            ->andWhere('p.faqStatus IS NULL')
            ->andWhere('p.keywordsStatus IS NOT NULL')
            ->setParameter('done', BrandRagPipeline::STATUS_DONE);

        return $this->finishStageQuery($qb, $limit, $shard, $total);
    }

    /**
     * Бренды без логотипа для стадии app:brand:logo: активные/new с пустым logo,
     * у которых поиск ещё не делался либо зафейлился сетью (logo_status NULL | failed).
     * not_found/skipped — терминальны (без --force). --force берёт всех без логотипа.
     * leftJoin: бренд без строки пайплайна (p.id NULL) тоже подхватывается.
     */
    public function findForLogo(int $limit, int $shard = 0, int $total = 1, bool $force = false): array
    {
        $qb = $this->qb()
            ->leftJoin(BrandRagPipeline::class, 'p', 'WITH', 'p.brand = b')
            ->where('b.status IN (:statuses)')
            ->andWhere("b.logo IS NULL OR b.logo = ''")
            ->setParameter('statuses', [Statuses::Active, Statuses::New]);

        if (!$force) {
            $qb->andWhere('p.id IS NULL OR p.logoStatus IS NULL OR p.logoStatus = :failed')
                ->setParameter('failed', BrandRagPipeline::LOGO_FAILED);
        }

        return $this->finishStageQuery($qb, $limit, $shard, $total);
    }

    /**
     * Бренды, готовые к доставке на прод (isPublishReady, развёрнутый в SQL):
     * никогда не пушенные ИЛИ изменённые обогащением после пуша
     * (contentChangedAt > pushedAt). --force (includePushed) берёт все.
     * Ретраи: push_attempts < maxAttempts.
     */
    public function findReadyToPush(int $limit, int $shard = 0, int $total = 1, int $maxAttempts = 3, bool $includePushed = false, bool $oldestFirst = false): array
    {
        return $this->finishStageQuery(
            $this->readyToPushQb($maxAttempts, $includePushed),
            $limit,
            $shard,
            $total,
            $includePushed && $oldestFirst,
        );
    }

    /**
     * Сколько брендов готовы к доставке на прод — ТОТ ЖЕ предикат, что findReadyToPush.
     * Единый источник правды: дашборд (RagDashboardController) и отчёт (PipelineReportCommand)
     * зовут это вместо собственных raw-SQL-копий, которые расходились с DQL (§2③).
     */
    public function countReadyToPush(int $maxAttempts = 3, bool $includePushed = false): int
    {
        return (int) $this->readyToPushQb($maxAttempts, $includePushed)
            ->select('COUNT(b.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Единый предикат «готов к публикации» (SQL-зеркало BrandRagPipeline::isPublishReady):
     * никогда не пушенные ИЛИ изменённые обогащением после пуша (contentChangedAt > pushedAt).
     * --force (includePushed) берёт все. Ретраи: push_attempts < maxAttempts.
     * Качество описания (refusal-текст) гейтит ContentValidator на генерации + revalidate-content
     * демотирует уже-done refusal'ы в deferred — здесь хватает status=done.
     */
    private function readyToPushQb(int $maxAttempts, bool $includePushed): QueryBuilder
    {
        return $this->qb()
            ->innerJoin(BrandRagPipeline::class, 'p', 'WITH', 'p.brand = b')
            ->where('p.status = :done')
            ->andWhere('p.pushAttempts < :maxAttempts')
            ->andWhere($includePushed ? '1=1' : '(p.pushedAt IS NULL OR p.contentChangedAt > p.pushedAt)')
            ->andWhere('p.faqStatus IN (:faqOk)')
            ->andWhere('p.keywordsStatus IN (:kwOk)')
            ->andWhere("b.description IS NOT NULL AND b.description != ''")
            ->andWhere("b.metaTitle IS NOT NULL AND b.metaTitle != ''")
            ->andWhere("b.metaDescription IS NOT NULL AND b.metaDescription != ''")
            ->setParameter('done', BrandRagPipeline::STATUS_DONE)
            ->setParameter('maxAttempts', $maxAttempts)
            ->setParameter('faqOk', [BrandRagPipeline::FAQ_DONE, BrandRagPipeline::FAQ_SKIPPED])
            ->setParameter('kwOk', [BrandRagPipeline::KW_FOUND, BrandRagPipeline::KW_NOT_FOUND]);
    }

    private function finishStageQuery(QueryBuilder $qb, int $limit, int $shard, int $total, bool $oldestFirst = false, bool $leastAttemptsFirst = false): array
    {
        // Подтверждённо вне ниши (app:brand:niche-check) — не готовим, не пушим, не публикуем.
        // NULL (не проверен) и 'in' проходят: иначе гейт застопорит весь конвейер до прогона
        // классификатора. Единая точка для всех stage-finder'ов (scrape/embed/generate/push/…).
        $qb->andWhere("b.nicheStatus IS NULL OR b.nicheStatus != :nicheOff")
            ->setParameter('nicheOff', 'off');

        if ($total > 1) {
            $qb->andWhere('MOD(b.id, :total) = :shard')
                ->setParameter('total', $total)
                ->setParameter('shard', $shard);
        }

        // Ручной приоритет очереди (brand_rag_pipeline.priority): чем больше — тем раньше.
        $qb->addOrderBy('p.priority', 'DESC');

        // Меньше попыток — раньше: не застреваем на одних и тех же брендах при перевыборе.
        if ($leastAttemptsFirst) {
            $qb->addOrderBy('p.generateAttempts', 'ASC');
        }

        if ($oldestFirst) {
            // NULLs first (никогда не пушились), потом самые старые по pushedAt
            $qb->addOrderBy('CASE WHEN p.pushedAt IS NULL THEN 0 ELSE 1 END', 'ASC')
                ->addOrderBy('p.pushedAt', 'ASC')
                ->addOrderBy('b.id', 'ASC');
        } else {
            $qb->addOrderBy('b.id', 'ASC');
        }

        return $qb->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
