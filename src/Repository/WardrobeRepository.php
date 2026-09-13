<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\Wardrobe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Wardrobe>
 */
class WardrobeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Wardrobe::class);
    }

    public function findDefaultForOwner(User $owner): ?Wardrobe
    {
        return $this->findOneBy([
            'owner' => $owner,
            'isDefault' => true,
            'deletedAt' => null,
        ]);
    }

    /**
     * Активные дефолтные гардеробы всех владельцев — источник для ночного пакетного
     * конвейера (WardrobeDailyController::catalog). isDefault=true фильтрует дубли:
     * вещи привязаны к owner (WardrobeItemRepository::findActiveForUser), а не к
     * конкретному Wardrobe, поэтому несколько гардеробов одного owner'а дали бы
     * один и тот же набор вещей дважды.
     *
     * @return Wardrobe[]
     */
    public function findActiveDefaults(): array
    {
        return $this->findBy([
            'isDefault' => true,
            'status' => Wardrobe::STATUS_ACTIVE,
            'deletedAt' => null,
        ]);
    }
}
