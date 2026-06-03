<?php

namespace App\Repository;

use Nevinny\AdminCoreBundle\Enum\Statuses;
use App\Entity\Brand;
use App\Entity\BrandRagPipeline;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Brand>
 */
class BrandRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Brand::class);
    }

    /**
     * Найти бренды по букве
     */
    public function findBrandsByLetter(string $letter): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->setParameter('status', Statuses::Active)
            ->orderBy('b.title', 'ASC');

        if ($letter === '0-9') {
            $qb->andWhere('REGEXP(b.title, :regexp) = true')
                ->setParameter('regexp', '^[0-9]');
        } else {
            $qb->andWhere('UPPER(SUBSTRING(b.title, 1, 1)) = :letter')
                ->setParameter('letter', $letter);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Найти бренды по поисковому запросу
     */
    public function findBrandsBySearch(string $search): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->andWhere('b.title LIKE :search')
            ->setParameter('status', Statuses::Active)
            ->setParameter('search', '%' . $search . '%')
            ->orderBy('b.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Все активные бренды
     */
    public function findAllActiveBrands(): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->setParameter('status', Statuses::Active)
            ->orderBy('b.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Получить статистику по буквам
     */
    public function findSimilarBrands(Brand $brand, int $limit = 8): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->setParameter('status', Statuses::Active)
            ->andWhere('b.id != :excludeId')
            ->setParameter('excludeId', $brand->getId());

        $city = $brand->getCity();
        $styles = $brand->getStyles();

        if ($city) {
            $qb->orWhere('b.city = :city');
            $qb->setParameter('city', $city);
        }

        if (count($styles) > 0) {
            $qb->leftJoin('b.styles', 's')
               ->andWhere('s.id IN (:styleIds)')
               ->setParameter('styleIds', $styles->map(fn($s) => $s->getId())->toArray());
        }

        $qb->orderBy('b.created_at', 'DESC')
           ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    public function findFeaturedBrands(int $limit = 12): array
    {
        // Сначала пытаемся найти бренды с описанием (для лучшего UX)
        $results = $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->andWhere('b.description IS NOT NULL')
            ->andWhere('LENGTH(b.description) > 100')
            ->setParameter('status', Statuses::Active)
            ->orderBy('b.created_at', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        // Фолбэк: любые активные бренды (чтобы каталог не выглядел пустым)
        if (empty($results)) {
            $results = $this->createQueryBuilder('b')
                ->where('b.status = :status')
                ->setParameter('status', Statuses::Active)
                ->orderBy('b.created_at', 'DESC')
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
        }

        return $results;
    }

    public function getLetterStats(): array
    {
        $brands = $this->findAllActiveBrands();
        $stats = [];

        foreach (array_merge(range('A', 'Z'), ['0-9']) as $char) {
            $stats[$char] = 0;
        }

        foreach ($brands as $brand) {
            $firstChar = strtoupper(substr($brand->gettitle(), 0, 1));
            if (!ctype_alpha($firstChar)) {
                $firstChar = '0-9';
            }

            if (isset($stats[$firstChar])) {
                $stats[$firstChar]++;
            }
        }

        return $stats;
    }

    public function findWithDescriptionWithoutMeta(int $limit, int $shard = 0, int $total = 1): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.description IS NOT NULL')
            ->andWhere('b.description != :empty')
            ->andWhere('b.metaTitle IS NULL OR b.metaTitle = :empty')
            ->setParameter('empty', '');

        return $this->finishStageQuery($qb, $limit, $shard, $total);
    }

    public function findWithoutDescription(int $limit, int $shard = 0, int $total = 1): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.description IS NULL OR b.description = :empty')
            ->setParameter('empty', '');

        return $this->finishStageQuery($qb, $limit, $shard, $total);
    }

    /**
     * Бренды, которые нужно обогатить контактами.
     *
     * Пропускает (без --force):
     *  - бренды со статусом 'enriched' / 'partial' / 'not_found'
     *  - бренды, у которых уже есть хотя бы одна ссылка в brand_link
     *    (данные внесены вручную до запуска обогащения)
     *  - ошибочные бренды, исчерпавшие лимит попыток
     *
     * Включает:
     *  - contactEnrichedAt IS NULL И нет ссылок в brand_link
     *  - завершились ошибкой и ещё есть попытки
     *
     * @param bool $force если true — возвращает все бренды без фильтрации
     */
    public function findForContactEnrichment(int $limit, bool $force = false, int $maxErrorAttempts = 3): array
    {
        if ($force) {
            return $this->createQueryBuilder('b')
                ->orderBy('b.id', 'ASC')
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
        }

        return $this->createQueryBuilder('b')
            // Бренды без маркера обогащения И без уже существующих ссылок
            ->leftJoin('b.links', 'l')
            ->where(
                '(' .
                    'b.contactEnrichedAt IS NULL AND l.id IS NULL' .
                ') OR (' .
                    'b.contactStatus = :error AND b.contactAttempts < :maxAttempts' .
                ')'
            )
            ->setParameter('error', 'error')
            ->setParameter('maxAttempts', $maxErrorAttempts)
            ->groupBy('b.id')
            ->orderBy('b.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    // =========================================================================
    // RAG pipeline stage finders (см. BrandRagPipeline). Каждый воркер берёт
    // бренды на своём этапе; шардинг MOD(b.id, total)=shard даёт непересекающиеся
    // наборы для параллельных процессов (ноль конфликтов записи).
    // =========================================================================

    /** Этап 1 — на скрейп: нет pipeline-строки, либо pending, либо повторяемый scrape_failed. */
    public function findForScrape(int $limit, int $shard = 0, int $total = 1, int $maxAttempts = 3): array
    {
        $qb = $this->createQueryBuilder('b')
            ->leftJoin(BrandRagPipeline::class, 'p', 'WITH', 'p.brand = b')
            ->where('b.status = :active')
            ->andWhere('p.id IS NULL OR p.status = :pending OR (p.status = :failed AND p.scrapeAttempts < :max)')
            ->setParameter('active', Statuses::Active)
            ->setParameter('pending', BrandRagPipeline::STATUS_PENDING)
            ->setParameter('failed', BrandRagPipeline::STATUS_SCRAPE_FAILED)
            ->setParameter('max', $maxAttempts);

        return $this->finishStageQuery($qb, $limit, $shard, $total);
    }

    /** Этап 2 — на эмбеддинг: pipeline в статусе scraped, либо повторяемый embed_failed. */
    public function findForEmbed(int $limit, int $shard = 0, int $total = 1, int $maxAttempts = 3): array
    {
        $qb = $this->createQueryBuilder('b')
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
        $qb = $this->createQueryBuilder('b')
            ->innerJoin(BrandRagPipeline::class, 'p', 'WITH', 'p.brand = b')
            ->where('p.status = :embedded OR (p.status = :failed AND p.generateAttempts < :max)')
            ->setParameter('embedded', BrandRagPipeline::STATUS_EMBEDDED)
            ->setParameter('failed', BrandRagPipeline::STATUS_GENERATE_FAILED)
            ->setParameter('max', $maxAttempts);

        return $this->finishStageQuery($qb, $limit, $shard, $total);
    }

    /** Бренды без собранных ключевиков (нет ни одной строки brand_keyword). */
    public function findForKeywords(int $limit, int $shard = 0, int $total = 1): array
    {
        $qb = $this->createQueryBuilder('b')
            ->leftJoin(\App\Entity\BrandKeyword::class, 'k', 'WITH', 'k.brand = b')
            ->where('b.status = :active')
            ->andWhere('k.id IS NULL')
            ->setParameter('active', Statuses::Active)
            ->groupBy('b.id');

        return $this->finishStageQuery($qb, $limit, $shard, $total);
    }

    private function finishStageQuery(QueryBuilder $qb, int $limit, int $shard, int $total): array
    {
        if ($total > 1) {
            $qb->andWhere('MOD(b.id, :total) = :shard')
                ->setParameter('total', $total)
                ->setParameter('shard', $shard);
        }

        return $qb->orderBy('b.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
