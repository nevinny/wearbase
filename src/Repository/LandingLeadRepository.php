<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LandingLead;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LandingLead>
 */
class LandingLeadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LandingLead::class);
    }
}
