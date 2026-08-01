<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Brand;
use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Service\LlmService;
use App\Service\Social\CaptionGenerator;
use App\Service\Social\SlideScript;
use App\Service\Social\Source\BrandDescriptionCaptionSource;
use App\Service\Social\Source\CaptionSourceInterface;
use App\Service\Social\Source\PillarCaptionSource;
use App\Service\Social\SocialRubrics;
use PHPUnit\Framework\TestCase;

class CaptionGeneratorTest extends TestCase
{
    /**
     * LLM, возвращающий присланный ему prompt — так в подписи окажется промпт,
     * и можно проверить, что в нём есть привязанные факты и выбранный угол подачи.
     */
    private function generator(): CaptionGenerator
    {
        $llm = $this->createMock(LlmService::class);
        $llm->method('generate')->willReturnCallback(static fn (string $prompt): string => $prompt);

        return new CaptionGenerator(
            [new PillarCaptionSource($llm), new BrandDescriptionCaptionSource($llm)],
            'https://wearbase.ru',
        );
    }

    private function compose(string $rubric, string $date): string
    {
        $post = (new SocialPost())
            ->setChannel((new SocialChannel())->setPlatform(SocialChannel::PLATFORM_TG))
            ->setRubric($rubric)
            ->setScheduledAt(new \DateTime($date, new \DateTimeZone('Europe/Moscow')));

        $this->generator()->compose($post, (new SocialRubrics())->get($rubric));

        return (string) $post->getCaption();
    }

    /** Подпись пишет LLM на привязанных фактах пиллара — цифры из facts должны попасть в промпт. */
    public function testGroundsOnPillarFacts(): void
    {
        self::assertStringContainsString('30–67%', $this->compose('calculator', '2026-01-05'));
        self::assertStringContainsString('Угол подачи:', $this->compose('calculator', '2026-01-05'));
    }

    /** Соседние недели → другой угол подачи (раньше тексты повторялись из-за seed=post.id). */
    public function testAngleRotatesAcrossWeeks(): void
    {
        $w02 = $this->compose('manifesto', '2026-01-05'); // ISO-неделя 02
        $w03 = $this->compose('manifesto', '2026-01-12'); // 03
        $w04 = $this->compose('manifesto', '2026-01-19'); // 04

        self::assertCount(3, array_unique([$w02, $w03, $w04]), 'Три недели подряд — три разных угла');
    }

    /** Тот же слот (рубрика+неделя+канал) → тот же угол: стабильность при ретраях генерации. */
    public function testStableForSameSlot(): void
    {
        self::assertSame(
            $this->compose('vs_marketplace', '2026-01-05'),
            $this->compose('vs_marketplace', '2026-01-05'),
        );
    }

    /**
     * Галерея/Reels — первая строка подписи это hookA реализованного сценария («факт вперёд»,
     * v4) плюс призыв досмотреть карусель (script_key/script_json на посте уже проставлены
     * SocialGenerateCommand'ом до compose()).
     */
    public function testGalleryCaptionPrefixIsHookAPlusCarouselCta(): void
    {
        $brand = (new Brand())->setTitle('Тест')->setCity('Пермь');
        $script = new SlideScript('Маркетплейс: до 67%.', 'У этого бренда — 0%.', [], 'Тест', 'Пермь · обувь', 'Сохрани, чтобы не искать.', 'f2.fee|b.none|c.save');

        $post = (new SocialPost())
            ->setChannel((new SocialChannel())->setPlatform(SocialChannel::PLATFORM_IG))
            ->setRubric('brand_gallery')
            ->setBrand($brand)
            ->setScriptKey($script->scriptKey)
            ->setScriptJson(json_encode($script->toArray(), JSON_UNESCAPED_UNICODE));

        $this->galleryGenerator()->compose($post, (new SocialRubrics())->get('brand_gallery'));

        self::assertStringStartsWith("Маркетплейс: до 67%. Дальше — в карусели.\n\n", (string) $post->getCaption());
    }

    /** Reels — тот же hookA, но призыв про ролик, а не карусель. */
    public function testGalleryCaptionPrefixForReelsUsesVideoCta(): void
    {
        $script = new SlideScript('Вместо Zara?', 'Но не копия.', [], 'Тест', 'Российский бренд', 'Сохрани, чтобы не искать.', 'h1.departed|b.det0|c.save');

        $post = (new SocialPost())
            ->setChannel((new SocialChannel())->setPlatform(SocialChannel::PLATFORM_IG))
            ->setRubric('brand_reels')
            ->setBrand((new Brand())->setTitle('Тест'))
            ->setScriptKey($script->scriptKey)
            ->setScriptJson(json_encode($script->toArray(), JSON_UNESCAPED_UNICODE));

        $this->galleryGenerator()->compose($post, (new SocialRubrics())->get('brand_reels'));

        self::assertStringStartsWith("Вместо Zara? Дальше — в ролике.\n\n", (string) $post->getCaption());
    }

    /**
     * Reels показывает подпись ОДНОВРЕМЕННО с первым кадром — имя/город бренда в первых 125
     * знаках подписи выдаёт развязку раньше кадра с ней. Тело от источника, начинающееся с
     * имени бренда, переставляется в конец.
     */
    public function testGalleryCaptionDoesNotSpoilBrandNameInFirst125Chars(): void
    {
        $brand = (new Brand())->setTitle('Ромашка')->setCity('Тверь');
        $script = new SlideScript('Начинали для себя.', 'Чей — в конце.', [], 'Ромашка', 'Тверь', 'Сохрани, чтобы не искать.', 'f1.rag|b.rag1|c.save');

        $post = (new SocialPost())
            ->setChannel((new SocialChannel())->setPlatform(SocialChannel::PLATFORM_IG))
            ->setRubric('brand_reels')
            ->setBrand($brand)
            ->setScriptKey($script->scriptKey)
            ->setScriptJson(json_encode($script->toArray(), JSON_UNESCAPED_UNICODE));

        $source = new class implements CaptionSourceInterface {
            public function key(): string
            {
                return SocialRubrics::SOURCE_FOUNDER_STORY;
            }

            public function body(SocialPost $post): string
            {
                // Типичный вывод FounderStoryCaptionSource (3–4 предложения, до 60 слов) —
                // начинает именно с имени бренда.
                return 'Бренд «Ромашка» создала мастерица из Твери. Сегодня в нём живёт та же теплота, '
                    . 'что была в первых вещах, сшитых на кухне, а не в цеху. Здесь всё ещё делают вещи '
                    . 'медленно и вручную, для тех, кто ценит детали в каждой мелочи.';
            }
        };

        (new CaptionGenerator([$source], 'https://wearbase.ru'))->compose($post, (new SocialRubrics())->get('brand_reels'));

        $caption = (string) $post->getCaption();
        $firstChars = mb_substr($caption, 0, 125);

        self::assertStringNotContainsString('Ромашка', $firstChars);
        self::assertStringNotContainsString('Твери', $firstChars);
        // Предложение с именем не потеряно, просто переставлено в конец тела.
        self::assertStringContainsString('Бренд «Ромашка» создала мастерица из Твери.', $caption);
    }

    /** ctaLabel галерейных рубрик — «Бренд целиком», а не общее «Бренд напрямую». */
    public function testGalleryCtaLabelIsBrandWhole(): void
    {
        $brand = (new Brand())->setTitle('Тест')->setSlug('test-brand');
        $post = (new SocialPost())
            ->setChannel((new SocialChannel())->setPlatform(SocialChannel::PLATFORM_IG))
            ->setRubric('brand_gallery')
            ->setBrand($brand);

        $this->galleryGenerator()->compose($post, (new SocialRubrics())->get('brand_gallery'));

        self::assertSame('Бренд целиком', $post->getCtaLabel());
    }

    // --- E4: хэштеги brand_reels (§5.2/§8.3 плейбука) -----------------------------------------

    /** Ветка tags_0 — совсем без хэштегов (0 тегов у 14/16 разобранных рилсов, все аутлаеры). */
    public function testReelsTagsZeroVariantOmitsHashtags(): void
    {
        $post = (new SocialPost())
            ->setChannel((new SocialChannel())->setPlatform(SocialChannel::PLATFORM_IG))
            ->setRubric('brand_reels')
            ->setBrand((new Brand())->setTitle('Тест'))
            ->setVariant('flat_150|tags_0');

        $this->galleryGenerator()->compose($post, (new SocialRubrics())->get('brand_reels'));

        self::assertStringNotContainsString('#', (string) $post->getCaption());
    }

    /** Ветка tags_3 (контроль) — как сейчас, 3 тега рубрики из SocialRubrics. */
    public function testReelsTagsThreeVariantKeepsHashtags(): void
    {
        $post = (new SocialPost())
            ->setChannel((new SocialChannel())->setPlatform(SocialChannel::PLATFORM_IG))
            ->setRubric('brand_reels')
            ->setBrand((new Brand())->setTitle('Тест'))
            ->setVariant('hook_hold|tags_3');

        $this->galleryGenerator()->compose($post, (new SocialRubrics())->get('brand_reels'));

        self::assertStringContainsString('#ПрямойБренд', (string) $post->getCaption());
    }

    /** brand_gallery не участвует в E4 — хэштеги остаются независимо от значения variant. */
    public function testGalleryRubricIgnoresTagsVariant(): void
    {
        $post = (new SocialPost())
            ->setChannel((new SocialChannel())->setPlatform(SocialChannel::PLATFORM_IG))
            ->setRubric('brand_gallery')
            ->setBrand((new Brand())->setTitle('Тест'))
            ->setVariant('logo_last');

        $this->galleryGenerator()->compose($post, (new SocialRubrics())->get('brand_gallery'));

        self::assertStringContainsString('#ПрямойБренд', (string) $post->getCaption());
    }

    private function galleryGenerator(): CaptionGenerator
    {
        $source = new class implements CaptionSourceInterface {
            public function key(): string
            {
                return SocialRubrics::SOURCE_FOUNDER_STORY;
            }

            public function body(SocialPost $post): string
            {
                return 'Тело подписи.';
            }
        };

        return new CaptionGenerator([$source], 'https://wearbase.ru');
    }
}
