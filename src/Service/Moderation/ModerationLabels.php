<?php

declare(strict_types=1);

namespace App\Service\Moderation;

/**
 * Человекочитаемые формулировки кодов премодерации (`brand_moderation.missing`
 * и `.red_flags`, которые проставляет app:brand:moderate-tick).
 *
 * Одна точка правды для баннера в ЛК и письма владельцу: коды вида
 * `production_place` / `logo_is_screenshot` владельцу бренда ничего не говорят.
 */
final class ModerationLabels
{
    /** Чего не хватает в карточке — формулировка как задача владельцу. */
    private const MISSING = [
        'logo'             => 'логотип бренда (не скриншот — файл PNG/SVG)',
        'price'            => 'хотя бы один товар с ценой',
        'inn'              => 'ИНН или ОГРНИП — подтверждение, что бренд ваш',
        'production_place' => 'где отшиваете (город, производство)',
        'founding_year'    => 'год основания бренда',
        'description'      => 'описание бренда — 2–3 абзаца о том, что и для кого шьёте',
        'links'            => 'ссылка на сайт или соцсети бренда',
    ];

    /** Что в карточке выглядит проблемой. */
    private const FLAGS = [
        'logo_is_screenshot'    => 'логотип загружен скриншотом — нужен исходник',
        'product_without_price' => 'у товара не указана цена',
        'no_links'              => 'нет ни одной ссылки на бренд — не можем проверить, что он существует',
        'empty_card'            => 'карточка почти пустая — по ней нечего показать покупателю',
        'near_duplicate'        => 'похоже на уже существующую карточку в каталоге',
    ];

    /**
     * @param array<int, string>|null $codes
     * @return array<int, string>
     */
    public static function missing(?array $codes): array
    {
        return self::translate($codes, self::MISSING);
    }

    /**
     * @param array<int, string>|null $codes
     * @return array<int, string>
     */
    public static function flags(?array $codes): array
    {
        return self::translate($codes, self::FLAGS);
    }

    /**
     * @param array<int, string>|null   $codes
     * @param array<string, string>     $map
     * @return array<int, string>
     */
    private static function translate(?array $codes, array $map): array
    {
        $out = [];
        foreach ($codes ?? [] as $code) {
            // Неизвестный код показываем как есть — лучше сырой код, чем молчание.
            $out[] = $map[$code] ?? $code;
        }

        return $out;
    }
}
