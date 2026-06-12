<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Currency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Currency>
 */
class CurrencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Currency::class);
    }

    public function findActive(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.isActive = true')
            ->orderBy('c.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findBase(): ?Currency
    {
        return $this->findOneBy(['isBase' => true]);
    }

    public function findByCode(string $code): ?Currency
    {
        return $this->findOneBy(['code' => strtoupper($code)]);
    }
}
