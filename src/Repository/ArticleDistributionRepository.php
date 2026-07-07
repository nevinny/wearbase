<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Article;
use App\Entity\ArticleDistribution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nevinny\AdminCoreBundle\Enum\Statuses;

/**
 * @extends ServiceEntityRepository<ArticleDistribution>
 */
class ArticleDistributionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArticleDistribution::class);
    }

    public function findCurrent(Article $article, string $platform): ?ArticleDistribution
    {
        return $this->findOneBy(['article' => $article, 'platform' => $platform, 'isCurrent' => true]);
    }

    public function nextVersion(Article $article, string $platform): int
    {
        $max = $this->createQueryBuilder('d')
            ->select('MAX(d.version)')
            ->where('d.article = :article')
            ->andWhere('d.platform = :platform')
            ->setParameter('article', $article)
            ->setParameter('platform', $platform)
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? 1 : ((int) $max + 1);
    }

    /**
     * Текущие версии под площадку, для опубликованных статей блога — источник для
     * фидов/выгрузок (напр. /rss/dzen.xml). Article подгружается eager (join), чтобы
     * не бить по БД в цикле у вызывающего кода.
     *
     * @return ArticleDistribution[]
     */
    public function findCurrentForPlatform(string $platform, string $locale, int $limit): array
    {
        return $this->createQueryBuilder('d')
            ->addSelect('a')
            ->join('d.article', 'a')
            ->where('d.platform = :platform')
            ->andWhere('d.isCurrent = true')
            ->andWhere('a.status = :status')
            ->andWhere('a.locale = :locale')
            ->andWhere('a.publishedAt IS NOT NULL')
            ->andWhere('a.publishedAt <= :now')
            ->setParameter('platform', $platform)
            ->setParameter('status', Statuses::Active)
            ->setParameter('locale', $locale)
            ->setParameter('now', new \DateTime())
            ->orderBy('a.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
