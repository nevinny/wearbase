<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\BrandTranslation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrandTranslation>
 */
class BrandTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrandTranslation::class);
    }

    /**
     * Найти перевод бренда по локали.
     */
    public function findForLocale(Brand $brand, string $locale): ?BrandTranslation
    {
        return $this->findOneBy(['brand' => $brand, 'locale' => $locale]);
    }

    /**
     * Все переводы бренда, индексированные по локали.
     *
     * @return array<string, BrandTranslation>
     */
    public function findAllForBrand(Brand $brand): array
    {
        $rows = $this->findBy(['brand' => $brand]);
        $result = [];
        foreach ($rows as $t) {
            $result[$t->getLocale()] = $t;
        }
        return $result;
    }

    /**
     * Найти перевод или создать новый (unsaved).
     */
    public function findOrNew(Brand $brand, string $locale): BrandTranslation
    {
        $t = $this->findForLocale($brand, $locale);
        if ($t === null) {
            $t = (new BrandTranslation())
                ->setBrand($brand)
                ->setLocale($locale);
        }
        return $t;
    }
}
