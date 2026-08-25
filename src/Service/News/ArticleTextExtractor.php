<?php

declare(strict_types=1);

namespace App\Service\News;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Извлечение текста статьи из HTML: вырезаем script/style/nav/хром,
 * берём <article> если есть, иначе body. Возвращает чистый текст
 * (двойной перевод строки между абзацами) для рерайта и шингл-гейта.
 */
final class ArticleTextExtractor
{
    private const STRIP_SELECTORS = 'script, style, noscript, iframe, form, svg, nav, header, footer, aside, figure, figcaption';

    /** Минимальный объём текста, ниже которого статья считается недогруженной. */
    public const MIN_TEXT_LENGTH = 200;

    public function extract(string $html): ?string
    {
        if (trim($html) === '') {
            return null;
        }

        $crawler = new Crawler($html);
        $doc = $crawler;
        try {
            $root = $crawler->filter('article');
            if ($root->count() > 0) {
                $doc = $root->eq(0);
            }
        } catch (\Throwable) {
            // битая разметка — работаем с тем, что распарсилось
        }

        $doc->filter(self::STRIP_SELECTORS)->each(function (Crawler $node): void {
            foreach ($node as $el) {
                $el->parentNode?->removeChild($el);
            }
        });

        $text = trim((string) $doc->text(null));
        // Схлопываем повторяющиеся пустые строки, режем «портянку» пробелов в строке
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return mb_strlen($text) >= self::MIN_TEXT_LENGTH ? $text : null;
    }
}
