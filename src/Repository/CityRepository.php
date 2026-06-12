<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\City;
use App\Entity\Country;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<City>
 */
class CityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, City::class);
    }

    /** Города страны, упорядоченные по населению (desc), затем по алфавиту */
    public function findByCountry(Country $country, int $limit = 200): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.country = :country')
            ->andWhere('c.isActive = true')
            ->setParameter('country', $country)
            ->orderBy('c.population', 'DESC')
            ->addOrderBy('c.nameRu', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Поиск по части названия (для автодополнения) */
    public function search(string $query, ?Country $country = null, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.country', 'co')
            ->addSelect('co')
            ->where('c.nameRu LIKE :q OR c.nameEn LIKE :q')
            ->andWhere('c.isActive = true')
            ->setParameter('q', '%' . $query . '%')
            ->orderBy('c.population', 'DESC')
            ->setMaxResults($limit);

        if ($country) {
            $qb->andWhere('c.country = :country')->setParameter('country', $country);
        }

        return $qb->getQuery()->getResult();
    }
}
