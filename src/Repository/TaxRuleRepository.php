<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Country;
use App\Entity\TaxRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaxRule>
 */
class TaxRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaxRule::class);
    }

    /**
     * Активные налоговые правила для страны.
     *
     * @return TaxRule[]
     */
    public function findForCountry(Country $country): array
    {
        return $this->createQueryBuilder('tr')
            ->where('tr.country = :country')
            ->andWhere('tr.isActive = true')
            ->setParameter('country', $country)
            ->orderBy('tr.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Основное налоговое правило (B2C) для страны.
     * Возвращает первое активное B2C-правило с наибольшей ставкой.
     */
    public function findPrimaryForCountry(Country $country): ?TaxRule
    {
        return $this->createQueryBuilder('tr')
            ->where('tr.country = :country')
            ->andWhere('tr.isActive = true')
            ->andWhere('tr.appliesToB2c = true')
            ->setParameter('country', $country)
            ->orderBy('tr.rate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Рассчитать итоговый налог для заказа из страны.
     *
     * @return array{tax: float, rate: float, type: string}
     */
    public function calculateForOrder(Country $country, float $amountRub, bool $isB2b = false): array
    {
        $qb = $this->createQueryBuilder('tr')
            ->where('tr.country = :country')
            ->andWhere('tr.isActive = true')
            ->setParameter('country', $country);

        if ($isB2b) {
            $qb->andWhere('tr.appliesToB2b = true');
        } else {
            $qb->andWhere('tr.appliesToB2c = true');
        }

        /** @var TaxRule|null $rule */
        $rule = $qb->orderBy('tr.rate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($rule === null) {
            return ['tax' => 0.0, 'rate' => 0.0, 'type' => TaxRule::TYPE_NONE];
        }

        return [
            'tax'  => $rule->calculateTax($amountRub),
            'rate' => (float) $rule->getRate(),
            'type' => $rule->getTaxType(),
        ];
    }
}
