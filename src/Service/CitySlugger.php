<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Слаг города для индексируемых посадочных /{_locale}/cities/{slug}.
 * brand.city хранится строкой на русском — слаг строится транслитом на лету,
 * обратное разрешение — перебором фактических городов каталога (их десятки).
 */
class CitySlugger
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

    public function slugify(string $city): string
    {
        $s = mb_strtolower(trim($city));
        $s = strtr($s, self::MAP);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);

        return trim($s, '-');
    }

    /**
     * @param string[] $cities фактические названия городов из каталога
     */
    public function resolve(string $slug, array $cities): ?string
    {
        foreach ($cities as $city) {
            if ($this->slugify($city) === $slug) {
                return $city;
            }
        }

        return null;
    }
}
