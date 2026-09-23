<?php

namespace App\Repository;

use App\Entity\CompetitorArticle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitorArticle>
 */
class CompetitorArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitorArticle::class);
    }

    public function findByUrl(string $url): ?CompetitorArticle
    {
        return $this->findOneBy(['url' => mb_substr($url, 0, 768)]);
    }
}
