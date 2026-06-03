<?php

namespace App\Service\Discovery;

/**
 * Классифицирует URL источника по типу для очереди/документа/Qdrant payload.
 *
 * Порядок проверок важен: host-сигналы (маркетплейс/соцсеть) приоритетнее флага
 * own-site — DB-ссылка на instagram должна стать social, а не own_site.
 *
 * Возможные значения: own_site | marketplace | social | article_review | mention.
 * (catalog в этой таксономии не детектим — сворачиваем в mention.)
 */
class SourceTypeClassifier
{
    /** Хосты маркетплейсов (суффиксное совпадение домена). */
    private const MARKETPLACE_HOSTS = [
        'ozon.ru',
        'wildberries.ru',
        'lamoda.ru',
        'market.yandex.ru',
        'aliexpress.ru',
        'avito.ru',
        'sbermegamarket.ru',
    ];

    /** Хосты соцсетей/мессенджеров/видео. */
    private const SOCIAL_HOSTS = [
        'instagram.com',
        'vk.com',
        't.me',
        'telegram.me',
        'youtube.com',
        'tiktok.com',
    ];

    /** Маркеры отзыва/обзора в заголовке или сниппете. */
    private const REVIEW_MARKERS = ['отзыв', 'обзор', 'рейтинг'];

    public function classify(string $url, string $title, string $snippet, bool $isOwnSite): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if ($host !== '') {
            if ($this->hostMatches($host, self::MARKETPLACE_HOSTS)) {
                return 'marketplace';
            }
            if ($this->hostMatches($host, self::SOCIAL_HOSTS)) {
                return 'social';
            }
        }

        if ($isOwnSite) {
            return 'own_site';
        }

        $hay = mb_strtolower($title . ' ' . $snippet);
        foreach (self::REVIEW_MARKERS as $marker) {
            if (str_contains($hay, $marker)) {
                return 'article_review';
            }
        }

        return 'mention';
    }

    /** Совпадение по самому хосту или его поддомену (suffix match на границе точки). */
    private function hostMatches(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }
}
