<?php

declare(strict_types=1);

namespace App\Enum;

/** Рубрикатор wearbase — классифицирует та же LLM, что делает рерайт. */
enum NewsRubric: string
{
    case Fashion = 'fashion';   // мода/тренды
    case Kids = 'kids';         // дети/родители
    case Wardrobe = 'wardrobe'; // гардероб/капсулы/уход за вещами
    case Other = 'other';       // прочее

    public static function tryFromMixed(?string $value): self
    {
        $v = mb_strtolower(trim((string) $value));
        return match (true) {
            in_array($v, ['мода', 'моде', 'тренды', 'fashion'], true) => self::Fashion,
            in_array($v, ['дети', 'детское', 'родители', 'kids'], true) => self::Kids,
            in_array($v, ['гардероб', 'капсула', 'уход за одеждой', 'wardrobe'], true) => self::Wardrobe,
            default => self::Other,
        };
    }
}
