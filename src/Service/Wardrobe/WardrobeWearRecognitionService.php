<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Repository\WardrobeItemRepository;

class WardrobeWearRecognitionService
{
    public function __construct(private readonly WardrobeAiService $ai, private readonly WardrobeItemRepository $items) {}

    /** @return array<int, array{item:WardrobeItem,confidence:string}> */
    public function candidates(string $photoPath, User $subject): array
    {
        $wardrobe = $this->items->findActiveForUser($subject);
        $selected = [];
        foreach ($this->ai->recognizeOutfitPhoto($photoPath, $subject) as $garment) {
            $best = null;
            $bestScore = 0;
            foreach ($wardrobe as $item) {
                if (isset($selected[$item->getId()])) {
                    continue;
                }
                $score = $this->score($garment, $item);
                if ($score > $bestScore) {
                    $best = $item;
                    $bestScore = $score;
                }
            }
            if ($best !== null && $bestScore >= 2) {
                $selected[$best->getId()] = ['item' => $best, 'confidence' => $bestScore >= 4 && $garment['confidence'] === 'high' ? 'high' : 'med'];
            }
        }
        return array_values($selected);
    }

    /** @param array{name:?string,category:?string,color:?string,confidence:string} $garment */
    private function score(array $garment, WardrobeItem $item): int
    {
        $score = 0;
        $score += $this->same($garment['category'], $item->getCategory()) ? 3 : 0;
        $score += $this->same($garment['color'], $item->getColorName()) ? 2 : 0;
        $score += $this->overlaps($garment['name'], $item->getName()) ? 1 : 0;
        return $score;
    }

    private function same(?string $left, ?string $right): bool
    {
        return $left !== null && $right !== null && mb_strtolower(trim($left)) === mb_strtolower(trim($right));
    }

    private function overlaps(?string $left, ?string $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }
        $tokens = static fn (string $value): array => array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value)) ?: [], static fn (string $token): bool => mb_strlen($token) >= 3);
        return array_intersect($tokens($left), $tokens($right)) !== [];
    }
}
