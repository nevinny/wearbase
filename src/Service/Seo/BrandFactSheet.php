<?php

namespace App\Service\Seo;

use App\Entity\Brand;
use App\Entity\BrandAttribute;
use App\Entity\BrandStore;
use App\Repository\BrandAttributeRepository;

/**
 * Фикс-поля карточки бренда для листиклов (приём Т—Ж,
 * docs/tj_wear_russian_benchmark.md): «Кратко / Хиты / Цены / Город / Офлайн».
 * Данные детерминированно ИЗ БД (Brand + Product + BrandStore + BrandAttribute),
 * НЕ из LLM. Нет данных по полю — строка не выводится (никаких «уточняйте на сайте»).
 */
class BrandFactSheet
{
    private const MAX_ANONS = 220; // «Кратко» — сканируемая строка, а не эссе

    public function __construct(private readonly BrandAttributeRepository $attributes)
    {
    }

    /** Markdown-список фикс-полей; пустая строка, если данных нет вообще. */
    public function build(Brand $brand): string
    {
        $rows = [];

        $anons = $this->shortAnons((string) $brand->getAnons());
        if ($anons !== '') {
            $rows[] = '- **Кратко:** ' . $anons;
        }

        $hits = [];
        foreach ($brand->getProducts() as $p) {
            $t = trim((string) $p->getTitle());
            if ($t !== '') {
                $hits[] = $t;
            }
            if (count($hits) >= 3) {
                break;
            }
        }
        if ($hits !== []) {
            $rows[] = '- **Хиты:** ' . implode(', ', $hits);
        }

        if (null !== $price = $this->priceLine($brand)) {
            $rows[] = '- **Цены:** ' . $price;
        }

        $city = trim((string) $brand->getCity());
        if ($city !== '') {
            $rows[] = '- **Город:** ' . $city;
        }

        if (null !== $offline = $this->offlineLine($brand)) {
            $rows[] = '- **Офлайн:** ' . $offline;
        }

        return implode("\n", $rows);
    }

    /**
     * «Кратко» — сканируемая строка, не эссе: целые предложения из anons,
     * пока помещаются в MAX_ANONS символов (первое берём всегда, при
     * переполнении обрезаем с «…»).
     */
    private function shortAnons(string $anons): string
    {
        $anons = trim((string) preg_replace('/\s+/u', ' ', $anons));
        if ($anons === '' || mb_strlen($anons) <= self::MAX_ANONS) {
            return $anons;
        }

        $sentences = preg_split('/(?<=[.!?…])\s+/u', $anons) ?: [$anons];
        $out = '';
        foreach ($sentences as $s) {
            $candidate = $out === '' ? $s : $out . ' ' . $s;
            if ($out !== '' && mb_strlen($candidate) > self::MAX_ANONS) {
                break;
            }
            $out = $candidate;
        }
        if (mb_strlen($out) > self::MAX_ANONS) {
            $out = rtrim(mb_substr($out, 0, self::MAX_ANONS), " \t.,;:—-") . '…';
        }

        return $out;
    }

    /** «от N ₽» из реальных цен товаров; иначе ценовой сегмент из brand_attribute. */
    private function priceLine(Brand $brand): ?string
    {
        $prices = [];
        foreach ($brand->getProducts() as $p) {
            $v = $p->getPrice() ?? $p->getMinPrice();
            if ($v !== null && $v > 0) {
                $prices[] = $v;
            }
        }
        if ($prices !== []) {
            return 'от ' . number_format(min($prices), 0, ',', ' ') . ' ₽';
        }

        $segments = [];
        foreach ($this->attributes->findByBrand($brand) as $attr) {
            $value = trim($attr->getValue());
            if ($attr->getName() === BrandAttribute::NAME_PRICE_SEGMENT && $value !== '') {
                $segments[$value] = true;
            }
        }
        if ($segments === []) {
            return null;
        }

        return 'сегмент — ' . implode(', ', array_slice(array_keys($segments), 0, 2));
    }

    /** Одна точка → «Город, адрес»; несколько → «N офлайн-точек (города)». */
    private function offlineLine(Brand $brand): ?string
    {
        /** @var BrandStore[] $stores */
        $stores = $brand->getStores()->toArray();
        if ($stores === []) {
            return null;
        }

        if (count($stores) === 1) {
            $s    = $stores[0];
            $city = trim((string) $s->getCity());
            $addr = trim($s->getAddress());
            // адрес часто уже начинается с города — не дублируем «Москва, Москва, …»
            if ($city !== '' && mb_stripos($addr, $city) === 0) {
                $city = '';
            }
            $line = trim(implode(', ', array_filter([$city, $addr])));

            return $line !== '' ? $line : null;
        }

        $cities = [];
        foreach ($stores as $s) {
            $c = trim((string) $s->getCity());
            if ($c !== '') {
                $cities[$c] = true;
            }
        }

        $n     = count($stores);
        $label = $n . ' ' . $this->plural($n, 'офлайн-точка', 'офлайн-точки', 'офлайн-точек');
        $list  = array_slice(array_keys($cities), 0, 3);

        return $list === [] ? $label : $label . ' (' . implode(', ', $list) . ')';
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        $mod = $n % 100;
        if ($mod >= 11 && $mod <= 14) {
            return $many;
        }

        return match ($mod % 10) {
            1       => $one,
            2, 3, 4 => $few,
            default => $many,
        };
    }
}
