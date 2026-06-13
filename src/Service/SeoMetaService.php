<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Сборка и «ремонт» SEO-meta (docs/seo_adoption_plan.md п.5; доктрина _fit из
 * пакета _seo: «ремонт длины/структуры вместо реджекта» — тримим по ГРАНИЦЕ СЛОВА,
 * а не mid-word, как делал mb_substr).
 *
 * Лимиты согласованы с ContentValidator (title ≤60, description ≤155).
 */
class SeoMetaService
{
    public const MAX_TITLE       = 60;
    public const MAX_DESCRIPTION = 155;

    private const BRAND_SUFFIX = ' | WEARBASE';

    /** Тримит текст к лимиту по границе слова (без обрезанных слов и хвостовой пунктуации). */
    public function fit(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        // отрезаем последнее (вероятно обрезанное) слово, если резали не по пробелу
        if (mb_substr($text, $max, 1) !== ' ') {
            $pos = mb_strrpos($cut, ' ');
            if ($pos !== false) {
                $cut = mb_substr($cut, 0, $pos);
            }
        }

        return rtrim($cut, " \t\n\r\0\x0B.,;:—-");
    }

    /**
     * Title из названия (+город): берём самый информативный вариант, который влезает в 60.
     * Branded-anchor — безопасно и желательно (доктрина пакета по анкорам).
     */
    public function buildTitle(string $brandTitle, ?string $city = null): string
    {
        $brandTitle = trim($brandTitle);
        $city       = trim((string) $city);

        $candidates = [];
        if ($city !== '') {
            $candidates[] = sprintf('%s — бренд одежды, %s%s', $brandTitle, $city, self::BRAND_SUFFIX);
        }
        $candidates[] = sprintf('%s — бренд одежды%s', $brandTitle, self::BRAND_SUFFIX);
        $candidates[] = sprintf('%s — бренд одежды', $brandTitle);
        $candidates[] = $brandTitle . self::BRAND_SUFFIX;

        foreach ($candidates as $candidate) {
            if (mb_strlen($candidate) <= self::MAX_TITLE) {
                return $candidate;
            }
        }

        return $this->fit($brandTitle, self::MAX_TITLE);
    }

    /**
     * Meta-description: из готового текста (description/anons) по границе слова до 155.
     * Если источника нет — детерминированный шаблон из названия (+город).
     */
    public function buildDescription(?string $source, string $brandTitle, ?string $city = null): string
    {
        $source = trim((string) $source);
        if ($source !== '') {
            return $this->fit($source, self::MAX_DESCRIPTION);
        }

        $city = trim((string) $city);
        $template = $city !== ''
            ? sprintf('%s — российский бренд одежды из города %s. Каталог, фото и отзывы на WEARBASE.', $brandTitle, $city)
            : sprintf('%s — российский бренд одежды. Каталог, фото и отзывы на WEARBASE.', $brandTitle);

        return $this->fit($template, self::MAX_DESCRIPTION);
    }
}
