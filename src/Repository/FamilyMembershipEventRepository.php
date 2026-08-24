<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FamilyMembershipEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FamilyMembershipEvent> */
final class FamilyMembershipEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, FamilyMembershipEvent::class); }
}
