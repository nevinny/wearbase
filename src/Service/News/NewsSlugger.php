<?php

declare(strict_types=1);

namespace App\Service\News;

/**
 * Публичный слаг заметки: транслит заголовка (та же карта, что CitySlugger),
 * уникальность добивается суффиксом -2, -3…
 */
final class NewsSlugger
{
    private const MAP = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    public function slugify(string $title): string
    {
        $s = mb_strtolower(trim($title));
        $s = strtr($s, self::MAP);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $slug = trim($s, '-');

        return substr($slug, 0, 180) !== '' ? substr($slug, 0, 180) : 'news';
    }
}
