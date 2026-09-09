<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FittingFeedback;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FittingFeedback> */
class FittingFeedbackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FittingFeedback::class);
    }
}
