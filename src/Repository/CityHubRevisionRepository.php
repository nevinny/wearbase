<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BrandContentRevision;
use App\Entity\CityHub;
use App\Entity\CityHubRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CityHubRevision>
 */
class CityHubRevisionRepository extends ServiceEntityRepository
{
    /** До/после сравниваются в одной шкале (rate-proxy) — то же окно, что у брендов. */
    private const WINDOW_DAYS = 14;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CityHubRevision::class);
    }

    /** Активная (живая) ревизия хаба — зеркалит city_hub.*. */
    public function findActive(CityHub $hub): ?CityHubRevision
    {
        return $this->findOneBy(['hub' => $hub, 'isActive' => true]);
    }

    public function hasAny(CityHub $hub): bool
    {
        return null !== $this->findOneBy(['hub' => $hub]);
    }

    /** Сколько раз хаб реально перегенерировался (source=generated) — основа для attempt. */
    public function countGenerated(CityHub $hub): int
    {
        return (int) $this->count(['hub' => $hub, 'source' => CityHubRevision::SOURCE_GENERATED]);
    }

    /**
     * Эксперименты к оценке: pending + окно замера истекло.
     * @return CityHubRevision[]
     */
    public function findDueForEvaluation(\DateTimeInterface $now, int $limit = 200): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.verdict = :pending')
            // Только АКТИВНАЯ ревизия описывает то, что реально лежит на странице.
            // Перекрытая (isActive=false) осталась бы pending навсегда, а замер «после»
            // у неё считался бы по НОВОМУ контенту → ложный вердикт, а на loss ещё и
            // откат живого текста к предшественнику уже заменённой ревизии.
            ->andWhere('r.isActive = true')
            ->andWhere('r.measureAfter IS NOT NULL AND r.measureAfter <= :now')
            ->setParameter('pending', BrandContentRevision::VERDICT_PENDING)
            ->setParameter('now', $now)
            ->orderBy('r.measureAfter', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Предыдущая ревизия того же хаба (append-only ⇒ порядок id = порядок времени).
     * Цель отката: если её нет — истории до этого эксперимента не было (baseline
     * пуст, был формульный fallback), откатывать некуда — хаб просто выключают.
     */
    public function findPrevious(CityHubRevision $rev): ?CityHubRevision
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.hub = :hub')
            ->andWhere('r.id < :id')
            ->setParameter('hub', $rev->getHub())
            ->setParameter('id', $rev->getId())
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Снимок метрик страницы хаба по URL (аналог BrandContentVersioner::gscSnapshot,
     * но по page_url — у city-страниц нет brand_id): показы/клики за окно из
     * gsc_page_stats + факт индексации (gsc_index_status ИЛИ свежий in_search
     * Яндекса — тот же критерий свежести ±3 дня, что и у брендов). Только /ru/ —
     * остальные локали noindex (см. CLAUDE.md, международный SEO), их GSC/Яндекс
     * не видят и видеть не должны.
     *
     * @return array{0:int,1:int,2:bool} [показы, клики, в индексе] за последние WINDOW_DAYS.
     */
    public function citySnapshot(string $slug): array
    {
        $conn  = $this->getEntityManager()->getConnection();
        $url   = '%/ru/cities/' . $slug;
        $since = (new \DateTime('-' . self::WINDOW_DAYS . ' days'))->format('Y-m-d');

        $row = $conn->fetchAssociative(
            'SELECT SUM(impressions) impr, SUM(clicks) clicks
             FROM gsc_page_stats WHERE page_url LIKE :url AND day >= :since',
            ['url' => $url, 'since' => $since],
        ) ?: ['impr' => 0, 'clicks' => 0];

        $indexedGsc = (bool) $conn->fetchOne(
            'SELECT MAX(indexed) FROM gsc_index_status WHERE page_url LIKE :url',
            ['url' => $url],
        );
        $inSearch = (bool) $conn->fetchOne(
            'SELECT MAX(s.in_search) FROM yandex_index_status s
             WHERE s.page_url LIKE :url
               AND s.last_checked_at >= (SELECT DATE_SUB(MAX(i.last_checked_at), INTERVAL 3 DAY)
                                         FROM yandex_index_status i)',
            ['url' => $url],
        );

        return [
            (int) $row['impr'],
            (int) $row['clicks'],
            $indexedGsc || $inSearch,
        ];
    }
}
