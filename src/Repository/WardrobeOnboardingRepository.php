<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WardrobeOnboarding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WardrobeOnboarding> */
class WardrobeOnboardingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WardrobeOnboarding::class);
    }

    public function findForSubject(User $subject): ?WardrobeOnboarding
    {
        return $this->findOneBy(['subject' => $subject]);
    }
}
