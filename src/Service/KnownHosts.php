<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Единый источник правды для списков хостов маркетплейсов/соцсетей — раньше
 * дублировались отдельно в `App\Service\Seo\CompetitorPageClassifier` (SEO gap-анализ)
 * и `App\Service\Discovery\SourceTypeClassifier` (RAG discover-конвейер), уже успели
 * разойтись (код-ревью 2026-09-23: ok.ru/facebook.com/threads.net/pinterest.com были
 * только в первом). Таксономии классификаторов разные (article/marketplace/social/
 * official_brand_site vs own_site/marketplace/social/article_review/mention) — сам
 * классификатор не сливаем, только списки хостов, на которые оба ссылаются.
 */
final class KnownHosts
{
    /** @var string[] */
    public const MARKETPLACES = [
        'wildberries.ru', 'ozon.ru', 'lamoda.ru', 'aliexpress.ru', 'aliexpress.com',
        'market.yandex.ru', 'sbermegamarket.ru', 'avito.ru', 'kupivip.ru', 'goods.ru',
    ];

    /** @var string[] */
    public const SOCIAL = [
        'vk.com', 'instagram.com', 't.me', 'telegram.me', 'ok.ru', 'youtube.com',
        'tiktok.com', 'facebook.com', 'threads.net', 'pinterest.com',
    ];

    /** Совпадение по самому хосту или его поддомену (suffix match на границе точки). */
    public static function matches(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }
}
