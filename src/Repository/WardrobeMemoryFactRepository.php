<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WardrobeMemoryFact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WardrobeMemoryFact> */
class WardrobeMemoryFactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, WardrobeMemoryFact::class); }

    public function findSource(User $subject, string $sourceType, int $sourceId): ?WardrobeMemoryFact
    {
        return $this->findOneBy(['profileSubject' => $subject, 'sourceType' => $sourceType, 'sourceId' => $sourceId]);
    }

    /** @return WardrobeMemoryFact[] */
    public function findActive(User $subject): array
    {
        return $this->findBy(['profileSubject' => $subject, 'deletedAt' => null], ['updatedAt' => 'DESC']);
    }
}
