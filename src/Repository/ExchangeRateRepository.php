<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Currency;
use App\Entity\ExchangeRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExchangeRate>
 */
class ExchangeRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExchangeRate::class);
    }

    /**
     * Возвращает последний актуальный курс для пары валют.
     */
    public function findLatest(Currency $base, Currency $target): ?ExchangeRate
    {
        return $this->createQueryBuilder('er')
            ->where('er.baseCurrency = :base')
            ->andWhere('er.targetCurrency = :target')
            ->setParameter('base', $base)
            ->setParameter('target', $target)
            ->orderBy('er.rateDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Все актуальные курсы для одной базовой валюты (последний курс на каждую цель).
     * Используется для инициализации CurrencyConverter.
     *
     * @return ExchangeRate[]
     */
    public function findLatestForBase(Currency $base): array
    {
        // Подзапрос: MAX(rate_date) для каждой пары
        return $this->createQueryBuilder('er')
            ->leftJoin('er.targetCurrency', 'tc')
            ->addSelect('tc')
            ->where('er.baseCurrency = :base')
            ->setParameter('base', $base)
            ->andWhere('er.rateDate = (
                SELECT MAX(er2.rateDate)
                FROM App\Entity\ExchangeRate er2
                WHERE er2.baseCurrency = :base
                  AND er2.targetCurrency = er.targetCurrency
            )')
            ->getQuery()
            ->getResult();
    }

    /**
     * История курсов для пары за последние N дней.
     *
     * @return ExchangeRate[]
     */
    public function findHistory(Currency $base, Currency $target, int $days = 30): array
    {
        $since = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('er')
            ->where('er.baseCurrency = :base')
            ->andWhere('er.targetCurrency = :target')
            ->andWhere('er.rateDate >= :since')
            ->setParameters([
                'base'   => $base,
                'target' => $target,
                'since'  => $since,
            ])
            ->orderBy('er.rateDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
