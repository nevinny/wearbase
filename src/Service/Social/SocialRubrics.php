<?php

namespace App\Service\Social;

use App\Entity\SocialPost;

/**
 * Каталог рубрик контент-плана — единый источник истины (DRY) для планировщика,
 * генератора подписей и рендера медиа. Сетка и привязка к пилларам — docs/marketing_instagram.md §2/§3.
 *
 * source:
 *   - template — ядро-сообщение (манифест/калькулятор/сравнение): LLM пишет на привязанных
 *     фактах пиллара (grounding, ноль выдумок про конкурента/цифры), угол подачи ротируется.
 *   - llm — брендовая рубрика: подпись генерится из описания бренда.
 *   - founder_story — история бренда из RAG-фактов (BrandRagService), фолбэк на llm-описание.
 *   - demand — ответ на топ-запрос бренда из Wordstat (brand_keyword), фолбэк на llm-описание.
 *   - departed — «чем заменить ушедших» из config/social/departed_brands.yaml, без бренда.
 * Все пишет LLM → aiGenerated=true. auto=false → пост создаётся в held (ручной просмотр: Reels/UGC).
 */
final class SocialRubrics
{
    public const SOURCE_TEMPLATE      = 'template';
    public const SOURCE_LLM           = 'llm';
    public const SOURCE_FOUNDER_STORY = 'founder_story';
    public const SOURCE_DEMAND        = 'demand';
    public const SOURCE_DEPARTED      = 'departed';

    /**
     * @var array<string,array{day:int,hour:int,source:string,needsBrand:bool,media:string,auto:bool,hashtags:string[]}>
     *   day — день недели 1(Пн)..7(Вс).
     */
    private const CATALOG = [
        'brand_week' => [
            'day' => 1, 'hour' => 11, 'source' => self::SOURCE_FOUNDER_STORY, 'needsBrand' => true,
            'media' => SocialPost::MEDIA_IMAGE, 'auto' => true,
            'hashtags' => ['#ПрямойБренд', '#российскиебренды', '#brandweek'],
        ],
        // day=0 — вне еженедельной сетки (forWeekday() матчит только 1..7): рубрика вытеснена
        // 'demand' (вт), но её def нужен как backing-рубрика для SocialPlanner::AUTO_FALLBACK.
        'calculator' => [
            'day' => 0, 'hour' => 12, 'source' => self::SOURCE_TEMPLATE, 'needsBrand' => false,
            'media' => SocialPost::MEDIA_NONE, 'auto' => true,
            'hashtags' => ['#ПрямойБренд', '#безмаркетплейса', '#wildberries'],
        ],
        'demand' => [
            'day' => 2, 'hour' => 12, 'source' => self::SOURCE_DEMAND, 'needsBrand' => true,
            'media' => SocialPost::MEDIA_IMAGE, 'auto' => true,
            'hashtags' => ['#ПрямойБренд', '#российскиебренды', '#подборкабрендов'],
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
        // day=0 — вне еженедельной сетки: рубрика вытеснена 'replace_departed' (пт), def
        // остаётся backing-рубрикой для SocialPlanner::AUTO_FALLBACK.
        'vs_marketplace' => [
            'day' => 0, 'hour' => 12, 'source' => self::SOURCE_TEMPLATE, 'needsBrand' => false,
            'media' => SocialPost::MEDIA_NONE, 'auto' => true,
            'hashtags' => ['#ПрямойБренд', '#безмаркетплейса'],
        ],
        'replace_departed' => [
            'day' => 5, 'hour' => 12, 'source' => self::SOURCE_DEPARTED, 'needsBrand' => false,
            'media' => SocialPost::MEDIA_IMAGE, 'auto' => true,
            'hashtags' => ['#ПрямойБренд', '#российскиебренды', '#чемзаменить'],
        ],
        // day=0 — вне еженедельной сетки: ставится пачкой командой app:social:enqueue-brand-gallery
        // по всем брендам, у которых есть фото в brand_image. Медиа — карусель из РЕАЛЬНЫХ фото
        // бренда (BrandGalleryImages), не AI-карточка.
        'brand_gallery' => [
            'day' => 0, 'hour' => 15, 'source' => self::SOURCE_FOUNDER_STORY, 'needsBrand' => true,
            'media' => SocialPost::MEDIA_CAROUSEL, 'auto' => true,
            'hashtags' => ['#ПрямойБренд', '#российскиебренды', '#сделановроссии'],
        ],
        // Те же нормализованные слайды, собранные в Reels-слайдшоу: Reels — единственная
        // поверхность IG с существенной раздачей НЕ подписчикам, поэтому контент идёт в оба
        // формата. Ставится той же командой enqueue-brand-gallery.
        'brand_reels' => [
            'day' => 0, 'hour' => 19, 'source' => self::SOURCE_FOUNDER_STORY, 'needsBrand' => true,
            'media' => SocialPost::MEDIA_REELS, 'auto' => true,
            'hashtags' => ['#ПрямойБренд', '#российскиебренды', '#сделановроссии'],
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
