<?php

declare(strict_types=1);

namespace App\Service\Outreach;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Тёплый оффер «Размещение под ключ» 5000₽: текст письма + вспомогательные выборки
 * (клики/показы бренда за окно, похожие бренды). Вынесено из
 * {@see \App\Command\OutreachWarmRefreshCommand} для переиспользования реальной
 * отправкой ({@see BrandOutreachMailer::sendWarmOfferFor()}) — один источник текста
 * для драфт-файла (человек-гейт) и для письма, которое реально уходит.
 */
class WarmOfferService
{
    public const WINDOW_DAYS  = 28;
    private const SIMILAR_LIMIT = 3;
    private const CATALOG_BASE  = 'https://wearbase.ru';
    private const OFFER_URL     = 'https://wearbase.ru/ru/for-brands/placement';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Клики/показы конкретного бренда за окно {@see self::WINDOW_DAYS} дней. Тот же
     * дедуп, что в OutreachWarmRefreshCommand::fetchWarmLeads() (MAX по дню, потом SUM).
     *
     * @return array{clicks:int,impressions:int}
     */
    public function fetchStats(int $brandId): array
    {
        $since = (new \DateTimeImmutable())->modify('-' . self::WINDOW_DAYS . ' days')->format('Y-m-d');

        $row = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT COALESCE(SUM(clicks), 0) AS clicks, COALESCE(SUM(impressions), 0) AS impressions
                FROM (
                    SELECT day, MAX(clicks) AS clicks, MAX(impressions) AS impressions
                    FROM gsc_page_stats
                    WHERE brand_id = :id AND query IS NULL AND day >= :since
                    GROUP BY day
                ) per_day
            SQL,
            ['id' => $brandId, 'since' => $since],
            ['id' => ParameterType::INTEGER, 'since' => ParameterType::STRING],
        );

        return [
            'clicks'      => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
        ];
    }

    /**
     * Похожие бренды для in-group-конформизма в письме: сперва по общему стилю, если
     * не набрали лимит — добираем по городу. Только active, сам бренд исключён.
     *
     * @return list<array{title:string,slug:string}>
     */
    public function findSimilarBrands(int $brandId, string $city): array
    {
        $byStyle = $this->db->fetchAllAssociative(
            <<<'SQL'
                SELECT DISTINCT b2.title, b2.slug
                FROM brand_style_brand bsb1
                JOIN brand_style_brand bsb2 ON bsb2.brand_style_id = bsb1.brand_style_id AND bsb2.brand_id != bsb1.brand_id
                JOIN brand b2 ON b2.id = bsb2.brand_id AND b2.status = 'active'
                WHERE bsb1.brand_id = :id
                LIMIT
            SQL . ' ' . self::SIMILAR_LIMIT,
            ['id' => $brandId],
        );

        $result = array_map(static fn (array $r) => ['title' => (string) $r['title'], 'slug' => (string) $r['slug']], $byStyle);

        if (count($result) >= self::SIMILAR_LIMIT || trim($city) === '') {
            return $result;
        }

        $missing = self::SIMILAR_LIMIT - count($result);
        $known   = array_column($result, 'slug');
        $byCity  = $this->db->fetchAllAssociative(
            'SELECT title, slug FROM brand WHERE city = :city AND status = \'active\' AND id != :id ORDER BY id LIMIT ' . ($missing + count($known)),
            ['city' => $city, 'id' => $brandId],
        );
        foreach ($byCity as $r) {
            if (count($result) >= self::SIMILAR_LIMIT) {
                break;
            }
            if (in_array($r['slug'], $known, true)) {
                continue;
            }
            $result[] = ['title' => (string) $r['title'], 'slug' => (string) $r['slug']];
        }

        return $result;
    }

    /**
     * @param array{id:int,title:string,slug:string,email:string,city:?string,clicks:int,impressions:int} $lead
     * @param list<array{title:string,slug:string}> $similar
     * @return array{lead: array, similar: array, subject: string, body: string}
     */
    public function buildDraft(array $lead, array $similar): array
    {
        $url = sprintf('%s/ru/brands/%s', self::CATALOG_BASE, $lead['slug']);

        $subject = sprintf(
            '«%s» — вашу карточку уже нашли %d раз в поиске за месяц',
            $lead['title'], $lead['impressions'],
        );

        $similarLine = $similar !== []
            ? implode(', ', array_map(
                static fn (array $s) => sprintf('«%s» (%s/ru/brands/%s)', $s['title'], self::CATALOG_BASE, $s['slug']),
                $similar,
            ))
            : null;

        $body = sprintf(
            "Здравствуйте!\n\n" .
            "Представьте: следующий заказ приходит через Wearbase. У «%s» это уже начало " .
            "происходить — ваша карточка уже собрана и работает без вашего участия:\n%s\n\n" .
            "За последние 28 дней её нашли в поиске %d раз, %d человек перешли посмотреть.\n\n" .
            "%s" .
            "Можем полностью укомплектовать карточку и разместить вас в 10+ прямых каналах " .
            "(каталоги российских брендов, наша витрина, анонс в Telegram/VK) — кроме " .
            "маркетплейсов. Разово 5 000₽, дальше клиент и выручка — ваши.\n\n" .
            "Полный состав и оплата: %s\n\n" .
            "Интересно — отвечу деталями. Если нет — просто напишите «не надо», больше не побеспокою.\n\n" .
            'Анна Семянникова, куратор Wearbase',
            $lead['title'], $url,
            $lead['impressions'], $lead['clicks'],
            $similarLine !== null ? sprintf("Рядом с вами уже: %s.\n\n", $similarLine) : '',
            self::OFFER_URL,
        );

        return ['lead' => $lead, 'similar' => $similar, 'subject' => $subject, 'body' => $body];
    }
}
