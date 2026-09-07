<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ScheduledCommand;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledCommand>
 */
class ScheduledCommandRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledCommand::class);
    }

    /** @return ScheduledCommand[] Включённые задачи только указанного окружения. */
    public function findEnabled(string $environment): array
    {
        return $this->findBy(['enabled' => true, 'environment' => $environment], ['id' => 'ASC']);
    }

    public function findWardrobeIngestWorker(): ?ScheduledCommand
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.command LIKE :command')
            ->setParameter('command', 'app:wardrobe:ingest-drafts%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Включённые задачи, последний прогон которых упал (lastExitCode != 0) — для админ-дашборда. @return ScheduledCommand[] */
    public function findFailing(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.enabled = true')
            ->andWhere('c.lastExitCode IS NOT NULL')
            ->andWhere('c.lastExitCode != 0')
            ->orderBy('c.lastRunAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
