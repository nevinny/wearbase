<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Article;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nevinny\AdminCoreBundle\Enum\Statuses;

/**
 * @extends ServiceEntityRepository<Article>
 */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    /** @return Article[] */
    public function findPublished(string $locale, int $limit, int $offset = 0): array
    {
        return $this->publishedQb($locale)
            ->orderBy('a.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countPublished(string $locale): int
    {
        return (int) $this->publishedQb($locale)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOnePublishedBySlug(string $slug, string $locale): ?Article
    {
        return $this->publishedQb($locale)
            ->andWhere('a.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function publishedQb(string $locale): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->andWhere('a.locale = :locale')
            ->andWhere('a.publishedAt IS NOT NULL')
            ->andWhere('a.publishedAt <= :now')
            ->setParameter('status', Statuses::Active)
            ->setParameter('locale', $locale)
            ->setParameter('now', new \DateTime());
    }
}
