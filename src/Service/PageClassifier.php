<?php

namespace App\Service;

/**
 * Классификация публичного URL по типу страницы (brand/blog/style/city/other) —
 * общая для синков индексации (Яндекс.Вебмастер, GSC): обе таблицы трекают ВСЕ
 * страницы, не только бренды, и нужна единая логика классификации.
 *
 * Паттерны из реальных роутов (src/Controller/Brands/BrandsController.php,
 * src/Controller/BlogController.php), подтверждено live-выгрузкой sitemap.xml.
 */
class PageClassifier
{
    public function classify(string $url): string
    {
        if (preg_match('~/[a-z]{2}/brands/~', $url)) {
            return 'brand';
        }
        if (preg_match('~/[a-z]{2}/blog(?:/|$)~', $url)) {
            return 'blog';
        }
        if (preg_match('~/[a-z]{2}/styles?(?:/|$)~', $url)) {
            return 'style';
        }
        if (preg_match('~/[a-z]{2}/cities(?:/|$)~', $url)) {
            return 'city';
        }

        return 'other';
    }
}
