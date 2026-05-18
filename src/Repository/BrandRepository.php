<?php

namespace App\Repository;

use Nevinny\AdminCoreBundle\Enum\Statuses;
use App\Entity\Brand;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Brand>
 */
class BrandRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Brand::class);
    }

    /**
     * Найти бренды по букве
     */
    public function findBrandsByLetter(string $letter): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->setParameter('status', Statuses::Active)
            ->orderBy('b.title', 'ASC');

        if ($letter === '0-9') {
            $qb->andWhere('REGEXP(b.title, :regexp) = true')
                ->setParameter('regexp', '^[0-9]');
        } else {
            $qb->andWhere('UPPER(SUBSTRING(b.title, 1, 1)) = :letter')
                ->setParameter('letter', $letter);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Найти бренды по поисковому запросу
     */
    public function findBrandsBySearch(string $search): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->andWhere('b.title LIKE :search')
            ->setParameter('status', Statuses::Active)
            ->setParameter('search', '%' . $search . '%')
            ->orderBy('b.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Все активные бренды
     */
    public function findAllActiveBrands(): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->setParameter('status', Statuses::Active)
            ->orderBy('b.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Получить статистику по буквам
     */
    public function findSimilarBrands(Brand $brand, int $limit = 8): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->setParameter('status', Statuses::Active)
            ->andWhere('b.id != :excludeId')
            ->setParameter('excludeId', $brand->getId());

        $city = $brand->getCity();
        $styles = $brand->getStyles();

        if ($city) {
            $qb->orWhere('b.city = :city');
            $qb->setParameter('city', $city);
        }

        if (count($styles) > 0) {
            $qb->leftJoin('b.styles', 's')
               ->andWhere('s.id IN (:styleIds)')
               ->setParameter('styleIds', $styles->map(fn($s) => $s->getId())->toArray());
        }

        $qb->orderBy('b.created_at', 'DESC')
           ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    public function findFeaturedBrands(int $limit = 12): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->andWhere('b.description IS NOT NULL')
            ->andWhere('LENGTH(b.description) > 100')
            ->setParameter('status', Statuses::Active)
            ->orderBy('b.created_at', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getLetterStats(): array
    {
        $brands = $this->findAllActiveBrands();
        $stats = [];

        foreach (array_merge(range('A', 'Z'), ['0-9']) as $char) {
            $stats[$char] = 0;
        }

        foreach ($brands as $brand) {
            $firstChar = strtoupper(substr($brand->gettitle(), 0, 1));
            if (!ctype_alpha($firstChar)) {
                $firstChar = '0-9';
            }

            if (isset($stats[$firstChar])) {
                $stats[$firstChar]++;
            }
        }

        return $stats;
    }

    public function findWithDescriptionWithoutMeta(int $limit): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.description IS NOT NULL')
            ->andWhere('b.description != :empty')
            ->andWhere('b.metaTitle IS NULL OR b.metaTitle = :empty')
            ->setParameter('empty', '')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findWithoutDescription(int $limit): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.description IS NULL OR b.description = :empty')
            ->setParameter('empty', '')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
