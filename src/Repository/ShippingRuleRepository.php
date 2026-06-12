<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Country;
use App\Entity\ShippingRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShippingRule>
 */
class ShippingRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShippingRule::class);
    }

    /**
     * Активные правила доставки для страны, отсортированные по sortOrder.
     *
     * @return ShippingRule[]
     */
    public function findForCountry(Country $country): array
    {
        return $this->createQueryBuilder('sr')
            ->where('sr.country = :country')
            ->andWhere('sr.isActive = true')
            ->setParameter('country', $country)
            ->orderBy('sr.sortOrder', 'ASC')
            ->addOrderBy('sr.priceRub', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Самое дешёвое правило доставки для страны.
     */
    public function findCheapestForCountry(Country $country): ?ShippingRule
    {
        return $this->createQueryBuilder('sr')
            ->where('sr.country = :country')
            ->andWhere('sr.isActive = true')
            ->setParameter('country', $country)
            ->orderBy('sr.priceRub', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Все активные правила, индексированные по коду страны.
     *
     * @return array<string, ShippingRule[]>   ['RU' => [...], 'DE' => [...]]
     */
    public function findAllGroupedByCountry(): array
    {
        $rules = $this->createQueryBuilder('sr')
            ->join('sr.country', 'c')
            ->where('sr.isActive = true')
            ->orderBy('c.code', 'ASC')
            ->addOrderBy('sr.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($rules as $rule) {
            $code = $rule->getCountry()->getCode();
            $grouped[$code][] = $rule;
        }
        return $grouped;
    }

    /**
     * Правила доставки по перевозчику.
     *
     * @return ShippingRule[]
     */
    public function findByCarrier(string $carrier): array
    {
        return $this->findBy(['carrier' => $carrier, 'isActive' => true], ['sortOrder' => 'ASC']);
    }
}
