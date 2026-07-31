<?php

namespace App\Service\Social;

use App\Entity\SocialPost;
use App\Service\Social\Source\CaptionSourceInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Сборка подписи поста. Тон — честный/дерзкий (docs/marketing_strategy.md §5), без sterile-копии.
 *
 * Фасад: выбирает источник тела подписи по source-ключу рубрики (SocialRubrics::CATALOG →
 * CaptionSourceInterface, паттерн PaymentGatewayRegistry/app.payment_gateway), затем детерминированно
 * добавляет структурные части — хэштеги и CTA-ссылку с UTM.
 */
class CaptionGenerator
{
    /** Рубрики со сценарием слайдов v3 (SlideScriptComposer) — для них подпись получает
     *  первую строку по ступени лестницы хуков (см. scriptPrefix()). */
    private const GALLERY_RUBRICS = ['brand_gallery', 'brand_reels'];

    /** @var array<string, CaptionSourceInterface> */
    private array $sourcesByKey = [];

    /**
     * @param iterable<CaptionSourceInterface> $sources
     */
    public function __construct(
        #[TaggedIterator('app.social_caption_source')] iterable $sources,
        private readonly string $siteBaseUrl,
    ) {
        foreach ($sources as $source) {
            $this->sourcesByKey[$source->key()] = $source;
        }
    }

    /**
     * Наполнить пост: подпись (тело источника + хэштеги, БЕЗ сырого URL) и CTA-поля
     * (label + ссылка с UTM). Публикаторы оформляют ссылку под площадку (TG — кликабельный
     * текст, VK — текст+URL, IG — без URL). aiGenerated проставляет вызывающий.
     */
    public function compose(SocialPost $post, array $rubricDef): void
    {
        $source = $this->sourcesByKey[$rubricDef['source']]
            ?? throw new \RuntimeException(sprintf('Источник подписи «%s» не зарегистрирован.', $rubricDef['source']));

        $body = trim($source->body($post));

        $prefix = $this->scriptPrefix($post);
        if ($prefix !== null) {
            $body = $prefix . "\n\n" . $body;
        }

        $tags = implode(' ', $rubricDef['hashtags']);
        $post->setCaption($body . "\n\n" . $tags);

        [$label, $url] = $this->cta($post);
        $post->setCtaLabel($label)->setCtaUrl($url);
    }

    /**
     * Первая строка подписи галереи/Reels — по реализованной ступени лестницы хуков
     * (SocialGenerateCommand считает сценарий ДО этого вызова, script_key/script_json на посте
     * уже проставлены). Без города («без города — опустить префикс») строки h3/h4 остаются без
     * ведущего «{Город}. ».
     */
    private function scriptPrefix(SocialPost $post): ?string
    {
        if (!in_array($post->getRubric(), self::GALLERY_RUBRICS, true)) {
            return null;
        }

        $json = $post->getScriptJson();
        if ($json === null || $json === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $script = SlideScript::fromArray($data);

        $stage = explode('|', $script->scriptKey, 2)[0];
        if (str_starts_with($stage, 'h1.')) {
            // Формат hookA ступени H1 — 'Вместо {ИмяУшедшего}?' (SlideScriptComposer). Парсим,
            // а не переопределяем departed_brands.yaml заново — источник правды один.
            if (preg_match('/^Вместо (.+)\?$/u', $script->hookA, $m) === 1) {
                return 'Чем заменить ' . $m[1] . ' — ответ внутри.';
            }

            return null;
        }

        $city = trim((string) ($post->getBrand()?->getCity() ?? ''));
        $cityPrefix = $city !== '' ? $city . '. ' : '';

        return match (true) {
            str_starts_with($stage, 'h2.') => $cityPrefix . 'Угадай город по вещам — ответ в конце.',
            str_starts_with($stage, 'h3.') => $cityPrefix . 'Сначала факты, имя — в конце.',
            str_starts_with($stage, 'h4.') => $cityPrefix . 'Просто посмотри.',
            default => null,
        };
    }

    /**
     * CTA: текст-подпись + ссылка с UTM.
     * @return array{0:string,1:string} [label, url]
     */
    private function cta(SocialPost $post): array
    {
        $source = $post->getChannel()?->getPlatform() ?? 'social';
        $brand = $post->getBrand();

        if ($brand !== null && $brand->getSlug()) {
            // Галерея/Reels уже показали часть бренда (фото) — ссылка ведёт «досмотреть остальное»,
            // а не абстрактно «напрямую», как у остальных брендовых рубрик.
            $label = in_array($post->getRubric(), self::GALLERY_RUBRICS, true) ? 'Бренд целиком' : 'Бренд напрямую';

            return [$label, $this->withUtm('/ru/brands/' . $brand->getSlug(), $source, $post->getRubric(), $post->getId())];
        }

        return ['Каталог независимых русских брендов', $this->withUtm('/ru/', $source, $post->getRubric(), $post->getId())];
    }

    /**
     * Ссылка с UTM-метками — для отслеживания эффективности канала/рубрики в аналитике.
     * utm_content=p<id> — точная атрибуция клика к посту (app:social:ingest-clicks), id
     * известен на этапе generate, когда пост уже персистирован (planned → generated).
     */
    private function withUtm(string $path, string $source, string $rubric, ?int $postId): string
    {
        $params = [
            'utm_source'   => $source,                          // tg | vk | ig
            'utm_medium'   => 'social',
            'utm_campaign' => $rubric !== '' ? $rubric : 'social_auto',
        ];
        if ($postId !== null) {
            $params['utm_content'] = 'p' . $postId; // точная атрибуция клика к посту
        }
        $query = http_build_query($params);

        return rtrim($this->siteBaseUrl, '/') . $path . '?' . $query;
    }
}
