<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NewsItem;
use App\Enum\NewsItemStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method NewsItem|null find($id, $lockMode = null, $lockVersion = null)
 * @method NewsItem|null findOneBy(array $criteria, array $orderBy = null)
 */
class NewsItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsItem::class);
    }

    public function findBySourceAndGuidHash(int $sourceId, string $guidHash): ?NewsItem
    {
        return $this->findOneBy(['source' => $sourceId, 'guidHash' => $guidHash]);
    }

    /** Очередь process-команды: discovered/fetched, старые вперёд. @return NewsItem[] */
    public function findProcessable(int $limit): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.status IN (:statuses)')
            ->setParameter('statuses', NewsItemStatus::processable())
            ->orderBy('n.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Сколько item стало ready с начала суток — кап «готовых к публикации» в день. */
    public function countReadySince(\DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.status = :status')
            ->andWhere('n.readyAt >= :since')
            ->setParameter('status', NewsItemStatus::Ready)
            ->setParameter('since', \DateTime::createFromInterface($since))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Лента /news: published, свежие вперёд. @return NewsItem[] */
    public function findPublished(int $limit, int $offset = 0): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.status = :status')
            ->setParameter('status', NewsItemStatus::Published)
            ->orderBy('n.publishedAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countPublished(): int
    {
        return (int) $this->count(['status' => NewsItemStatus::Published]);
    }

    public function findOnePublishedBySlug(string $slug): ?NewsItem
    {
        return $this->findOneBy(['slug' => $slug, 'status' => NewsItemStatus::Published]);
    }
}
