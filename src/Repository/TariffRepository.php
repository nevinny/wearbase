<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tariff;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tariff>
 */
class TariffRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tariff::class);
    }

    public function findActive(): array
    {
        return $this->findBy(['isActive' => true], ['priceRub' => 'ASC']);
    }

    public function findOneByCode(string $code): ?Tariff
    {
        return $this->findOneBy(['code' => $code]);
    }
}
