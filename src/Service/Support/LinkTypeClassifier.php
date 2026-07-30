<?php

declare(strict_types=1);

namespace App\Service\Support;

/**
 * Нормализованный link_type ссылки бренда по хосту URL (link_type из enrichment
 * часто 'other'). Общий атом для клик-аналитики (OutboundClickController) и
 * записи ссылок при премодерации (ModerateTickCommand) — не дублируем список
 * хостов, иначе они разъедутся.
 */
final class LinkTypeClassifier
{
    public static function classify(string $url): string
    {
        $h = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        return match (true) {
            str_contains($h, 'instagram.com')                          => 'instagram',
            str_contains($h, 'vk.com') || str_contains($h, 'vkontakte') => 'vk',
            str_contains($h, 't.me') || str_contains($h, 'telegram.')   => 'telegram',
            str_contains($h, 'youtube.com') || str_contains($h, 'youtu.be') => 'youtube',
            str_contains($h, 'tiktok.com')                             => 'tiktok',
            str_contains($h, 'wildberries.') || str_contains($h, 'ozon.')
                || str_contains($h, 'lamoda.') || str_contains($h, 'market.yandex.') => 'marketplace',
            $h === ''                                                  => 'other',
            default                                                    => 'website',
        };
    }
}
