<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
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
    public function findVisibleTo(User $actor, array $subjects): array
    {
        $query = $this->createQueryBuilder('n')
            ->addSelect('category', 'parent', 'purchase')
            ->join('n.category', 'category')
            ->leftJoin('category.parent', 'parent')
            ->leftJoin('n.purchaseRequest', 'purchase')
            ->andWhere('n.subject = :actor AND n.family IS NULL')
            ->setParameter('actor', $actor)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC');

        $children = array_values(array_filter($subjects, static fn (User $subject): bool => $subject->getId() !== $actor->getId()
            && $subject->getFamilyRole() === User::FAMILY_ROLE_CHILD));
        if ($actor->isFamilyParent() && $actor->getFamily() !== null && $children !== []) {
            $query->orWhere('n.family = :family AND n.subject IN (:subjects)')
                ->setParameter('family', $actor->getFamily())
                ->setParameter('subjects', $children);
        }

        return $query->getQuery()->getResult();
    }
}
