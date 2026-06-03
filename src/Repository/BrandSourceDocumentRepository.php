<?php

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\BrandSourceDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrandSourceDocument>
 */
class BrandSourceDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrandSourceDocument::class);
    }

    /** @return BrandSourceDocument[] */
    public function findByBrand(Brand $brand): array
    {
        return $this->findBy(['brand' => $brand], ['id' => 'ASC']);
    }

    /** Документ с таким content_hash уже есть у бренда? (дедуп скрейпа) */
    public function existsForBrandHash(Brand $brand, string $contentHash): bool
    {
        return $this->count(['brand' => $brand, 'contentHash' => $contentHash]) > 0;
    }

    /** @return BrandSourceDocument[] документы бренда, ещё не залитые в Qdrant */
    public function findUnembeddedByBrand(Brand $brand): array
    {
        return $this->findBy(['brand' => $brand, 'embedded' => false], ['id' => 'ASC']);
    }

    /** Объём очищенного текста по бренду (для решения retrieve: всё vs поиск). */
    public function countByBrand(Brand $brand): int
    {
        return $this->count(['brand' => $brand]);
    }
}
