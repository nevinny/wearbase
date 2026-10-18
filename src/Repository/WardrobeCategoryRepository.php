<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WardrobeCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WardrobeCategory>
 */
class WardrobeCategoryRepository extends ServiceEntityRepository
{
    // Compatibility with labels emitted before vision used dictionary codes.
    private const LEGACY_CODES = [
        'футболки' => 'tshirt', 'рубашки' => 'shirt', 'майки' => 'tank_top',
        'топы' => 'top', 'водолазки' => 'turtleneck', 'свитшоты' => 'sweatshirt',
        'жилеты' => 'vest', 'джемперы' => 'jumper', 'кардиганы' => 'cardigan',
        'жакеты' => 'blazer', 'юбки' => 'skirt', 'куртки' => 'jacket',
        'косухи' => 'jacket', 'плащи' => 'raincoat', 'шапки' => 'hat',
        'шарфы' => 'scarf', 'ремни' => 'belt', 'сумки' => 'bag',
        'зимние кроссовки' => 'sneakers', 'комбинезоны' => 'jumpsuit',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WardrobeCategory::class);
    }

    /**
     * @return WardrobeCategory[]
     */
    public function findActiveTree(): array
    {
        return $this->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']);
    }

    /** @param WardrobeCategory[] $categories */
    public function resolveActive(string $value, array $categories): ?WardrobeCategory
    {
        $value = mb_strtolower(trim($value));
        foreach ($categories as $category) {
            if ($category->isActive() && ($category->getCode() === $value || mb_strtolower($category->getName()) === $value)) {
                return $category;
            }
        }
        $code = self::LEGACY_CODES[$value] ?? null;
        foreach ($categories as $category) {
            if ($code !== null && $category->isActive() && $category->getCode() === $code) {
                return $category;
            }
        }
        return null;
    }
}
