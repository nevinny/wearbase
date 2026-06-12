<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\ProductTranslation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductTranslation>
 */
class ProductTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductTranslation::class);
    }

    /**
     * Найти перевод товара по локали.
     */
    public function findForLocale(Product $product, string $locale): ?ProductTranslation
    {
        return $this->findOneBy(['product' => $product, 'locale' => $locale]);
    }

    /**
     * Все переводы товара, индексированные по локали.
     *
     * @return array<string, ProductTranslation>
     */
    public function findAllForProduct(Product $product): array
    {
        $rows = $this->findBy(['product' => $product]);
        $result = [];
        foreach ($rows as $t) {
            $result[$t->getLocale()] = $t;
        }
        return $result;
    }

    /**
     * Найти перевод или создать новый (unsaved).
     */
    public function findOrNew(Product $product, string $locale): ProductTranslation
    {
        $t = $this->findForLocale($product, $locale);
        if ($t === null) {
            $t = (new ProductTranslation())
                ->setProduct($product)
                ->setLocale($locale);
        }
        return $t;
    }
}
