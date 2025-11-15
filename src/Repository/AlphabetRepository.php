<?php

namespace App\Repository;

use App\Entity\Alphabet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nevinny\AdminCoreBundle\Enum\Statuses;

/**
 * @extends ServiceEntityRepository<Alphabet>
 */
class AlphabetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Alphabet::class);
    }
    public function findOneByLetterAndLocale(string $letter, string $locale): ?Alphabet
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.letter = :letter')
            ->andWhere('a.locale = :locale')
            ->andWhere('a.status = :status')
            ->setParameter('letter', $letter)
            ->setParameter('locale', $locale)
            ->setParameter('status', Statuses::Active)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
