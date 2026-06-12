<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OfferDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OfferDocument>
 */
class OfferDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OfferDocument::class);
    }

    /** Действующая опубликованная редакция документа для типа+локали. */
    public function findCurrentPublished(string $type, string $locale): ?OfferDocument
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.type = :type')
            ->andWhere('o.locale = :locale')
            ->andWhere('o.status = :status')
            ->andWhere('o.effectiveFrom <= :today')
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', OfferDocument::STATUS_PUBLISHED)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('o.effectiveFrom', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
