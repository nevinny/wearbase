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

    /** @return BrandSourceDocument[] (без soft-deleted) */
    public function findByBrand(Brand $brand): array
    {
        return $this->findBy(['brand' => $brand, 'deletedAt' => null], ['id' => 'ASC']);
    }

    /** @return BrandSourceDocument[] все документы бренда, включая soft-deleted (для админ-панели) */
    public function findAllByBrandIncludingDeleted(Brand $brand): array
    {
        return $this->findBy(['brand' => $brand], ['id' => 'ASC']);
    }

    /** Документ с таким content_hash уже есть у бренда? (дедуп скрейпа) */
    public function existsForBrandHash(Brand $brand, string $contentHash): bool
    {
        return $this->count(['brand' => $brand, 'contentHash' => $contentHash]) > 0;
    }

    /** Последний документ бренда по конкретному URL (для per-URL кеша скрейпа). */
    public function findByBrandUrl(Brand $brand, string $url): ?BrandSourceDocument
    {
        return $this->findOneBy(['brand' => $brand, 'url' => $url], ['id' => 'DESC']);
    }

    /** @return BrandSourceDocument[] документы бренда, ещё не залитые в Qdrant (без soft-deleted) */
    public function findUnembeddedByBrand(Brand $brand): array
    {
        return $this->findBy(['brand' => $brand, 'embedded' => false, 'deletedAt' => null], ['id' => 'ASC']);
    }

    /** Объём очищенного текста по бренду (для решения retrieve: всё vs поиск). Без soft-deleted. */
    public function countByBrand(Brand $brand): int
    {
        return $this->count(['brand' => $brand, 'deletedAt' => null]);
    }
}
