<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\WardrobeItem;

final class PreparedWardrobePhoto
{
    public static function revision(WardrobeItem $item): ?string
    {
        $cover = $item->getCoverPhoto();
        $source = $cover?->getFilePath() ?? $item->getPhoto();
        if ($source === null || $source === '') {
            return null;
        }

        return hash('sha256', ($cover?->getId() ?? 'legacy') . ':' . $source);
    }

    public static function path(string $projectDir, WardrobeItem $item): ?string
    {
        $revision = self::revision($item);

        return $revision === null || $item->getId() === null ? null
            : $projectDir . '/var/uploads/wardrobe_prepared/i' . $item->getId() . '-' . $revision . '.png';
    }
}
