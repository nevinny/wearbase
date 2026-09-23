<?php

namespace App\Service\Discovery;

use App\Service\KnownHosts;

/**
 * Классифицирует URL источника по типу для очереди/документа/Qdrant payload.
 *
 * Порядок проверок важен: host-сигналы (маркетплейс/соцсеть) приоритетнее флага
 * own-site — DB-ссылка на instagram должна стать social, а не own_site.
 *
 * Возможные значения: own_site | marketplace | social | article_review | mention.
 * (catalog в этой таксономии не детектим — сворачиваем в mention.)
 *
 * Списки хостов — KnownHosts (общие с App\Service\Seo\CompetitorPageClassifier,
 * вынесено 2026-09-23 — до этого дублировались отдельно и успели разойтись:
 * ok.ru/facebook.com/threads.net/pinterest.com здесь не детектились).
 */
class SourceTypeClassifier
{
    /** Маркеры отзыва/обзора в заголовке или сниппете. */
    private const REVIEW_MARKERS = ['отзыв', 'обзор', 'рейтинг'];

    public function classify(string $url, string $title, string $snippet, bool $isOwnSite): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if ($host !== '') {
            if (KnownHosts::matches($host, KnownHosts::MARKETPLACES)) {
                return 'marketplace';
            }
            if (KnownHosts::matches($host, KnownHosts::SOCIAL)) {
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
}
