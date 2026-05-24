<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * Варианты товаров бренда с нулевым остатком (активные товары).
     */
    public function findLowStockVariants(Brand $brand, int $threshold = 0): array
    {
        return $this->createQueryBuilder('p')
            ->select('p', 'v')
            ->join('p.variants', 'v')
            ->where('p.brand = :brand')
            ->andWhere('p.status = :status')
            ->andWhere('v.stockQty <= :threshold')
            ->andWhere('v.status = :vStatus')
            ->setParameter('brand', $brand)
            ->setParameter('status', 'active')
            ->setParameter('threshold', $threshold)
            ->setParameter('vStatus', 'active')
            ->orderBy('v.stockQty', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByBrandAndStatus(Brand $brand, string $status = 'active'): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.brand = :brand')
            ->andWhere('p.status = :status')
            ->setParameter('brand', $brand)
            ->setParameter('status', $status)
            ->orderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Каталог товаров с фильтрами и сортировкой.
     *
     * @param array{
     *   gender?: string|null,
     *   sale?: bool,
     *   brand?: int|null,
     *   search?: string|null,
     *   sort?: string,
     *   category?: int|null,
     *   style?: int|null,
     *   size?: string|null,
     *   min_price?: float|null,
     *   max_price?: float|null,
     * } $filters
     */
    public function findForCatalog(array $filters = [], int $limit = 48, int $offset = 0): array
    {
        $sort = $filters['sort'] ?? 'new';

        // Для сортировки по цене используем отдельный лёгкий запрос за IDs,
        // потом загружаем полные сущности — избегаем конфликта GROUP BY + addSelect entity.
        if (in_array($sort, ['price_asc', 'price_desc'], true)) {
            $ids = $this->buildCatalogIdsQuery($filters, $limit, $offset, $sort);
            if (empty($ids)) {
                return [];
            }
            return $this->createQueryBuilder('p')
                ->leftJoin('p.brand', 'b')
                ->leftJoin('p.productImages', 'pi', 'WITH', 'pi.isMain = true')
                ->leftJoin('p.variants', 'v', 'WITH', 'v.status = :vStatus')
                ->addSelect('b', 'pi', 'v')
                ->where('p.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->setParameter('vStatus', 'active')
                ->getQuery()
                ->getResult();
        }

        // Стандартный запрос (сортировка по новизне)
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.brand', 'b')
            ->leftJoin('p.productImages', 'pi', 'WITH', 'pi.isMain = true')
            ->leftJoin('p.variants', 'v', 'WITH', 'v.status = :vStatus')
            ->addSelect('b', 'pi', 'v')
            ->where('p.status = :status')
            ->andWhere('b.status = :brandStatus')
            ->setParameter('status', 'active')
            ->setParameter('brandStatus', 'active')
            ->setParameter('vStatus', 'active')
            ->orderBy('p.id', 'DESC');

        $this->applyFilters($qb, $filters);

        return $qb
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Возвращает список ID товаров, отсортированных по минимальной цене варианта.
     */
    private function buildCatalogIdsQuery(array $filters, int $limit, int $offset, string $sort): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.id, MIN(v.price) AS HIDDEN minPrice')
            ->leftJoin('p.brand', 'b')
            ->leftJoin('p.variants', 'v', 'WITH', 'v.status = :vStatus')
            ->where('p.status = :status')
            ->andWhere('b.status = :brandStatus')
            ->setParameter('status', 'active')
            ->setParameter('brandStatus', 'active')
            ->setParameter('vStatus', 'active')
            ->groupBy('p.id')
            ->orderBy('minPrice', $sort === 'price_asc' ? 'ASC' : 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $this->applyFilters($qb, $filters);

        return array_column($qb->getQuery()->getScalarResult(), 'id');
    }

    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        $gender = $filters['gender'] ?? null;
        if ($gender && in_array($gender, ['men', 'women', 'unisex', 'kids'], true)) {
            $qb->andWhere('p.gender = :gender')->setParameter('gender', $gender);
        }

        if (!empty($filters['sale'])) {
            $qb->andWhere('v.comparePrice IS NOT NULL')
               ->andWhere('v.comparePrice > v.price');
        }

        if (!empty($filters['brand'])) {
            $qb->andWhere('b.id = :brandId')->setParameter('brandId', (int) $filters['brand']);
        }

        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $qb->andWhere('p.title LIKE :search OR b.title LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        if (!empty($filters['category'])) {
            $qb->andWhere('p.category = :categoryId')
               ->setParameter('categoryId', (int) $filters['category']);
        }

        if (!empty($filters['style'])) {
            $sub = $this->createQueryBuilder('p2')
                ->select('p2.id')
                ->join('p2.styles', 's2')
                ->where('s2.id = :styleId');

            $qb->andWhere($qb->expr()->in('p.id', $sub->getDQL()))
               ->setParameter('styleId', (int) $filters['style']);
        }

        if (!empty($filters['size'])) {
            $qb->andWhere('v.size = :size')
               ->setParameter('size', $filters['size']);
        }

        if (!empty($filters['min_price'])) {
            $qb->andWhere('v.price >= :minPrice')
               ->setParameter('minPrice', (float) $filters['min_price']);
        }

        if (!empty($filters['max_price'])) {
            $qb->andWhere('v.price <= :maxPrice')
               ->setParameter('maxPrice', (float) $filters['max_price']);
        }
    }

    /**
     * @return Product[]
     */
    public function findSimilar(Product $product, int $limit = 8): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.brand', 'b')
            ->leftJoin('p.productImages', 'pi', 'WITH', 'pi.isMain = true')
            ->addSelect('b', 'pi')
            ->where('p.status = :status')
            ->andWhere('b.status = :brandStatus')
            ->andWhere('p.id != :productId')
            ->setParameter('status', 'active')
            ->setParameter('brandStatus', 'active')
            ->setParameter('productId', $product->getId())
            ->orderBy('p.id', 'DESC')
            ->setMaxResults($limit);

        if ($product->getCategory()) {
            $qb->andWhere('p.category = :category')
               ->setParameter('category', $product->getCategory());
        } else {
            $qb->andWhere('p.brand = :brand')
               ->setParameter('brand', $product->getBrand());
        }

        return $qb->getQuery()->getResult();
    }

    public function countForCatalog(array $filters = []): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(DISTINCT p.id)')
            ->leftJoin('p.brand', 'b')
            ->leftJoin('p.variants', 'v', 'WITH', 'v.status = :vStatus')
            ->where('p.status = :status')
            ->andWhere('b.status = :brandStatus')
            ->setParameter('status', 'active')
            ->setParameter('brandStatus', 'active')
            ->setParameter('vStatus', 'active');

        $this->applyFilters($qb, $filters);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
