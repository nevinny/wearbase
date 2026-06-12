<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Country;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Country>
 */
class CountryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Country::class);
    }

    /** Активные страны с предзагрузкой валюты, упорядоченные по sortOrder, затем по nameRu */
    public function findActive(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.defaultCurrency', 'cur')
            ->addSelect('cur')
            ->where('c.isActive = true')
            ->orderBy('c.sortOrder', 'ASC')
            ->addOrderBy('c.nameRu', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByCode(string $code): ?Country
    {
        return $this->findOneBy(['code' => strtoupper($code)]);
    }

    /** Поиск по части названия (для автодополнения) */
    public function search(string $query, int $limit = 20): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.nameRu LIKE :q OR c.nameEn LIKE :q')
            ->andWhere('c.isActive = true')
            ->setParameter('q', '%' . $query . '%')
            ->orderBy('c.sortOrder', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
