<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Brand;
use App\Entity\BrandTranslation;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Repository\BrandTranslationRepository;
use App\Repository\ProductTranslationRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig-расширение для получения переведённого контента брендов и товаров.
 *
 * Доступные функции в шаблонах:
 *
 *   {{ brand_t(brand, 'title') }}          → Название бренда на текущем языке
 *   {{ brand_t(brand, 'description') }}    → Описание бренда на текущем языке
 *   {{ product_t(product, 'title') }}      → Название товара на текущем языке
 *   {{ product_t(product, 'anons') }}      → Анонс товара на текущем языке
 *
 * Если перевод не найден — возвращает оригинальное значение из основной записи.
 * Если поле в переводе пустое — тоже возвращает оригинал (graceful fallback).
 *
 * Кеширует переводы в памяти на время запроса (по brand_id × locale,
 * product_id × locale), избегая N+1 запросов.
 */
class TranslationExtension extends AbstractExtension
{
    /** @var array<string, BrandTranslation|null> */
    private array $brandCache = [];

    /** @var array<string, ProductTranslation|null> */
    private array $productCache = [];

    public function __construct(
        private readonly BrandTranslationRepository   $brandTransRepo,
        private readonly ProductTranslationRepository $productTransRepo,
        private readonly RequestStack                 $requestStack,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('brand_t',   $this->brandField(...)),
            new TwigFunction('product_t', $this->productField(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            // {{ brand|brand_field('title') }}
            new TwigFilter('brand_field',   fn(Brand $b, string $field) => $this->brandField($b, $field)),
            new TwigFilter('product_field', fn(Product $p, string $field) => $this->productField($p, $field)),
        ];
    }

    /**
     * Возвращает переведённое поле бренда.
     *
     * @param string $field title|anons|description|metaTitle|metaDescription
     */
    public function brandField(Brand $brand, string $field): ?string
    {
        $locale = $this->getLocale();

        // Для русского — всегда оригинал, переводы не нужны
        if ($locale === 'ru') {
            return $this->getBrandOriginal($brand, $field);
        }

        $translation = $this->getBrandTranslation($brand, $locale);
        $value = $translation ? $this->getTranslationValue($translation, $field) : null;

        // Fallback на оригинал, если перевод пустой
        return $value ?: $this->getBrandOriginal($brand, $field);
    }

    /**
     * Возвращает переведённое поле товара.
     *
     * @param string $field title|anons|description|metaTitle|metaDescription
     */
    public function productField(Product $product, string $field): ?string
    {
        $locale = $this->getLocale();

        if ($locale === 'ru') {
            return $this->getProductOriginal($product, $field);
        }

        $translation = $this->getProductTranslation($product, $locale);
        $value = $translation ? $this->getTranslationValue($translation, $field) : null;

        return $value ?: $this->getProductOriginal($product, $field);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function getLocale(): string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale() ?? 'ru';
    }

    private function getBrandTranslation(Brand $brand, string $locale): ?BrandTranslation
    {
        $key = $brand->getId() . ':' . $locale;
        if (!array_key_exists($key, $this->brandCache)) {
            try {
                $this->brandCache[$key] = $this->brandTransRepo->findForLocale($brand, $locale);
            } catch (\Throwable) {
                $this->brandCache[$key] = null;
            }
        }
        return $this->brandCache[$key];
    }

    private function getProductTranslation(Product $product, string $locale): ?ProductTranslation
    {
        $key = $product->getId() . ':' . $locale;
        if (!array_key_exists($key, $this->productCache)) {
            try {
                $this->productCache[$key] = $this->productTransRepo->findForLocale($product, $locale);
            } catch (\Throwable) {
                $this->productCache[$key] = null;
            }
        }
        return $this->productCache[$key];
    }

    private function getTranslationValue(BrandTranslation|ProductTranslation $translation, string $field): ?string
    {
        return match ($field) {
            'title'           => $translation->getTitle(),
            'anons'           => $translation->getAnons(),
            'description'     => $translation->getDescription(),
            'metaTitle'       => $translation->getMetaTitle(),
            'metaDescription' => $translation->getMetaDescription(),
            default           => null,
        };
    }

    private function getBrandOriginal(Brand $brand, string $field): ?string
    {
        return match ($field) {
            'title'           => $brand->getTitle(),
            'anons'           => $brand->getAnons(),
            'description'     => $brand->getDescription(),
            'metaTitle'       => $brand->getTitle(), // SEO fallback
            'metaDescription' => $brand->getAnons(),
            default           => null,
        };
    }

    private function getProductOriginal(Product $product, string $field): ?string
    {
        return match ($field) {
            'title'           => $product->getTitle(),
            'anons'           => $product->getAnons(),
            'description'     => $product->getDescription(),
            'metaTitle'       => $product->getTitle(),
            'metaDescription' => $product->getAnons(),
            default           => null,
        };
    }
}
