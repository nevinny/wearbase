<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Family;
use App\Entity\User;
use App\Entity\WardrobeNeed;

/** @extends ServiceEntityRepository<WardrobeNeed> */
class WardrobeNeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WardrobeNeed::class);
    }

    /** @param User[] $subjects
     *  @return WardrobeNeed[]
     */
    public function findForFamily(Family $family, array $subjects): array
    {
        if ($subjects === []) {
            return [];
        }

        return $this->createQueryBuilder('n')
            ->addSelect('category', 'parent', 'purchase')
            ->join('n.category', 'category')
            ->leftJoin('category.parent', 'parent')
            ->leftJoin('n.purchaseRequest', 'purchase')
            ->andWhere('n.family = :family AND n.subject IN (:subjects)')
            ->setParameter('family', $family)
            ->setParameter('subjects', $subjects)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->getQuery()->getResult();
    }
}
