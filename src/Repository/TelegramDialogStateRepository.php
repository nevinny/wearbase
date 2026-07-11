<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TelegramDialogState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TelegramDialogState>
 */
class TelegramDialogStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TelegramDialogState::class);
    }

    public function findByChatId(string $chatId): ?TelegramDialogState
    {
        return $this->findOneBy(['chatId' => $chatId]);
    }
}
