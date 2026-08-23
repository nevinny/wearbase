<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FamilyBudget;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FamilyBudget> */
class FamilyBudgetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FamilyBudget::class);
    }

    public function findForSubject(User $subject): ?FamilyBudget
    {
        return $this->findOneBy(['subject' => $subject]);
    }
}
