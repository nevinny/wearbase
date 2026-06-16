<?php

namespace App\Service\Social;

use App\Entity\SocialPost;

/**
 * Каталог рубрик контент-плана — единый источник истины (DRY) для планировщика,
 * генератора подписей и рендера медиа. Сетка и привязка к пилларам — docs/marketing_instagram.md §2/§3.
 *
 * source:
 *   - template — ядро-сообщение (манифест/калькулятор/сравнение): фиксированный банк ротации,
 *     БЕЗ LLM (ноль галлюцинаций, on-message), aiGenerated=false.
 *   - llm — брендовая рубрика: подпись генерится из описания бренда, aiGenerated=true.
 * auto=false → пост создаётся в статусе held (ручной просмотр: Reels/UGC).
 */
final class SocialRubrics
{
    public const SOURCE_TEMPLATE = 'template';
    public const SOURCE_LLM      = 'llm';

    /**
     * @var array<string,array{day:int,hour:int,source:string,needsBrand:bool,media:string,auto:bool,hashtags:string[]}>
     *   day — день недели 1(Пн)..7(Вс).
     */
    private const CATALOG = [
        'brand_week' => [
            'day' => 1, 'hour' => 11, 'source' => self::SOURCE_LLM, 'needsBrand' => true,
            'media' => SocialPost::MEDIA_IMAGE, 'auto' => true,
            'hashtags' => ['#ПрямойБренд', '#российскиебренды', '#brandweek'],
        ],
        'calculator' => [
            'day' => 2, 'hour' => 12, 'source' => self::SOURCE_TEMPLATE, 'needsBrand' => false,
            'media' => SocialPost::MEDIA_NONE, 'auto' => true,
            'hashtags' => ['#ПрямойБренд', '#безмаркетплейса', '#wildberries'],
        ],
        'new_drops' => [
            'day' => 3, 'hour' => 13, 'source' => self::SOURCE_LLM, 'needsBrand' => true,
            'media' => SocialPost::MEDIA_IMAGE, 'auto' => true,
            'hashtags' => ['#ПрямойБренд', '#российскиебренды', '#сделановроссии'],
        ],
        'manifesto' => [
            'day' => 4, 'hour' => 12, 'source' => self::SOURCE_TEMPLATE, 'needsBrand' => false,
            'media' => SocialPost::MEDIA_NONE, 'auto' => true,
            'hashtags' => ['#ПрямойБренд', '#независимыебренды'],
        ],
        'vs_marketplace' => [
            'day' => 5, 'hour' => 12, 'source' => self::SOURCE_TEMPLATE, 'needsBrand' => false,
            'media' => SocialPost::MEDIA_NONE, 'auto' => true,
            'hashtags' => ['#ПрямойБренд', '#безмаркетплейса'],
        ],
        // Сб/Вс — ручные рубрики (UGC/лайфстайл): план создаёт held-заглушку.
        'ugc' => [
            'day' => 6, 'hour' => 13, 'source' => self::SOURCE_TEMPLATE, 'needsBrand' => false,
            'media' => SocialPost::MEDIA_NONE, 'auto' => false,
            'hashtags' => ['#ПрямойБренд'],
        ],
        'lifestyle' => [
            'day' => 7, 'hour' => 13, 'source' => self::SOURCE_LLM, 'needsBrand' => true,
            'media' => SocialPost::MEDIA_IMAGE, 'auto' => false,
            'hashtags' => ['#ПрямойБренд', '#российскиебренды'],
        ],
    ];

    /** @return array<string,array> весь каталог */
    public function all(): array
    {
        return self::CATALOG;
    }

    /** @return array{day:int,hour:int,source:string,needsBrand:bool,media:string,auto:bool,hashtags:string[]}|null */
    public function get(string $rubric): ?array
    {
        return self::CATALOG[$rubric] ?? null;
    }

    /**
     * Рубрики, назначенные на день недели (1..7).
     * @return string[] ключи рубрик
     */
    public function forWeekday(int $weekday): array
    {
        return array_keys(array_filter(self::CATALOG, static fn(array $r) => $r['day'] === $weekday));
    }
}
