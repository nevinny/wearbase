<?php

namespace App\Service\Social;

use App\Entity\Brand;
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
    /** Рубрики со сценарием слайдов (SlideScriptComposer) — для них подпись получает первую
     *  строку из hookA (см. scriptPrefix()) и тело от источника не должно спойлерить развязку
     *  (см. stripLeadingSpoiler()). */
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
            // Reels показывает подпись ОДНОВРЕМЕННО с первым кадром — имя/город бренда в первых
            // ~125 знаках подписи выдаёт развязку раньше кадра с ней.
            $body = $this->stripLeadingSpoiler($body, $post->getBrand());
            $body = $prefix . "\n\n" . $body;
        }

        $tags = $this->hashtags($post, $rubricDef);
        $post->setCaption($tags !== '' ? $body . "\n\n" . $tags : $body);

        [$label, $url] = $this->cta($post);
        $post->setCtaLabel($label)->setCtaUrl($url);
    }

    /**
     * E4 (§5.2/§8.3 плейбука) — ветка `tags_0` рубрики brand_reels выходит совсем без хэштегов:
     * 0 тегов у 14 из 16 разобранных вирусных рилсов, включая ВСЕ аутлаеры ×3 и выше. Ветка
     * `tags_3` (контроль) — как сейчас, 3 тега рубрики из SocialRubrics. Variant для brand_reels
     * пишет SocialGenerateCommand::assignExperimentVariant() как "{e1}|{e4}".
     */
    private function hashtags(SocialPost $post, array $rubricDef): string
    {
        if ($post->getRubric() === 'brand_reels' && str_ends_with((string) $post->getVariant(), '|tags_0')) {
            return '';
        }

        return implode(' ', $rubricDef['hashtags']);
    }

    /**
     * Первая строка подписи галереи/Reels — hookA реализованного сценария (SocialGenerateCommand
     * считает сценарий ДО этого вызова, script_key/script_json на посте уже проставлены) плюс
     * призыв досмотреть: hookA сам факт (v4, «ФАКТ ВПЕРЁД») и ничего не спойлерит, поэтому не
     * нужно ни парсить ступень отдельно, ни городить city-префикс.
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
        if ($script->hookA === '') {
            return null;
        }

        $tail = $post->getRubric() === 'brand_reels' ? ' Дальше — в ролике.' : ' Дальше — в карусели.';

        return $script->hookA . $tail;
    }

    /**
     * Если тело подписи от источника (LLM, FounderStoryCaptionSource/BrandDescriptionCaptionSource)
     * начинается с упоминания имени бренда или города — переставляет это первое предложение в
     * конец: развязка ролика/карусели называет бренд последним кадром, тело подписи не должно
     * называть его первым. Тело из одного предложения переставлять некуда — оставляем как есть
     * (редкий случай, источники пишут 2–4 предложения).
     */
    private function stripLeadingSpoiler(string $body, ?Brand $brand): string
    {
        $spoilers = array_values(array_filter([
            trim((string) $brand?->getTitle()),
            trim((string) $brand?->getCity()),
        ], static fn (string $s): bool => $s !== ''));
        if ($spoilers === [] || $body === '') {
            return $body;
        }

        $parts = preg_split('/(?<=[.!?])\s+/u', $body, 2);
        if (!isset($parts[1])) {
            return $body;
        }
        [$firstSentence, $rest] = $parts;

        foreach ($spoilers as $spoiler) {
            if ($this->mentionsWord($firstSentence, $spoiler)) {
                return trim($rest) . ' ' . trim($firstSentence);
            }
        }

        return $body;
    }

    /**
     * Русские падежи меняют окончание слова («Тверь» → «Твери», «Ромашка» → «Ромашкой») —
     * сверяем по укороченному префиксу, а не по целому слову, иначе declension прячет спойлер
     * от буквального substring-поиска.
     */
    private function mentionsWord(string $haystack, string $word): bool
    {
        $len = mb_strlen($word);
        $needle = mb_substr($word, 0, min(5, max(3, $len - 1)));

        return $needle !== '' && mb_stripos($haystack, $needle) !== false;
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
