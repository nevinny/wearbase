<?php

declare(strict_types=1);

namespace App\Service\Seo;

use App\Service\KnownHosts;
use App\Service\UrlFilter;

/**
 * Тип страницы в выдаче конкурентов (docs/seo_competitor_content.md, открытый
 * вопрос №1): только `article` идёт в скрейп+gap-анализ (app:seo:competitor-scan) —
 * не улучшать карточку Ozon и не анализировать структуру Instagram-профиля.
 *
 * Детерминированная эвристика по домену, без LLM/сети (легко тестируется).
 * Порядок проверки важен (первое совпадение побеждает):
 *   1. self/excluded (UrlFilter — wearbase.ru, job-агрегаторы) → other
 *   2. маркетплейс (KnownHosts::MARKETPLACES)                  → marketplace
 *   3. соцсеть (KnownHosts::SOCIAL)                             → social
 *   4. официальный сайт бренда (brand_link.link_type=website)  → official_brand_site
 *   5. иначе                                                    → article
 *
 * Списки хостов — общие с App\Service\Discovery\SourceTypeClassifier (KnownHosts),
 * не с UrlFilter::JOB_NOISE: там маркетплейсы НЕ исключаются из скрейпа намеренно
 * (у них бывают реальные материалы бренда), здесь же нужно просто не путать их со статьями.
 */
class CompetitorPageClassifier
{
    public const TYPE_ARTICLE        = 'article';
    public const TYPE_MARKETPLACE     = 'marketplace';
    public const TYPE_SOCIAL          = 'social';
    public const TYPE_OFFICIAL_BRAND  = 'official_brand_site';
    public const TYPE_OTHER           = 'other';

    public function __construct(
        private readonly UrlFilter $urlFilter,
    ) {
    }

    /**
     * @param string[] $officialHosts хосты официальных сайтов брендов (brand_link,
     *                                link_type=website), уже без «www.» — см. вызывающего
     */
    public function classify(string $url, array $officialHosts): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return self::TYPE_OTHER; // fail-closed, как UrlFilter
        }
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if ($this->urlFilter->isExcluded($url)) {
            return self::TYPE_OTHER;
        }
        if (KnownHosts::matches($host, KnownHosts::MARKETPLACES)) {
            return self::TYPE_MARKETPLACE;
        }
        if (KnownHosts::matches($host, KnownHosts::SOCIAL)) {
            return self::TYPE_SOCIAL;
        }
        if (in_array($host, $officialHosts, true)) {
            return self::TYPE_OFFICIAL_BRAND;
        }

        return self::TYPE_ARTICLE;
    }
}
