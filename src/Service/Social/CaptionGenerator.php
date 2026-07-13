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

        $body = $source->body($post);

        $tags = implode(' ', $rubricDef['hashtags']);
        $post->setCaption(trim($body) . "\n\n" . $tags);

        [$label, $url] = $this->cta($post);
        $post->setCtaLabel($label)->setCtaUrl($url);
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
            return ['Бренд напрямую', $this->withUtm('/ru/brands/' . $brand->getSlug(), $source, $post->getRubric(), $post->getId())];
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
