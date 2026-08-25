<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NewsSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method NewsSource|null find($id, $lockMode = null, $lockVersion = null)
 * @method NewsSource|null findOneBy(array $criteria, array $orderBy = null)
 * @method NewsSource[]    findAll()
 * @method NewsSource[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class NewsSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsSource::class);
    }
}
