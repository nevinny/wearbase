<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NotificationSettings;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationSettings>
 */
class NotificationSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationSettings::class);
    }

    /**
     * @return NotificationSettings[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }
}
