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
     * Галерея/Reels — первая строка подписи по РЕАЛИЗОВАННОЙ ступени лестницы хуков
     * (script_key/script_json на посте уже проставлены SocialGenerateCommand'ом до compose()).
     */
    public function testGalleryCaptionPrefixByScriptStage(): void
    {
        $brand = (new Brand())->setTitle('Тест')->setCity('Пермь');
        $script = new SlideScript('Угадай город.', 'Скажу в конце.', [], 'Тест', 'Пермь · обувь', 'Сохрани, чтобы не искать.', 'h2.city|b.rag2|c.save');

        $post = (new SocialPost())
            ->setChannel((new SocialChannel())->setPlatform(SocialChannel::PLATFORM_IG))
            ->setRubric('brand_gallery')
            ->setBrand($brand)
            ->setScriptKey($script->scriptKey)
            ->setScriptJson(json_encode($script->toArray(), JSON_UNESCAPED_UNICODE));

        $this->galleryGenerator()->compose($post, (new SocialRubrics())->get('brand_gallery'));

        self::assertStringStartsWith("Пермь. Угадай город по вещам — ответ в конце.\n\n", (string) $post->getCaption());
    }

    /** H1 (ушедший бренд) достаёт имя из уже собранного hookA 'Вместо {Имя}?' — не лезет в yaml повторно. */
    public function testGalleryCaptionPrefixForDepartedStage(): void
    {
        $script = new SlideScript('Вместо Zara?', 'Но не копия.', [], 'Тест', 'Российский бренд', 'Сохрани, чтобы не искать.', 'h1.departed|b.det0|c.save');

        $post = (new SocialPost())
            ->setChannel((new SocialChannel())->setPlatform(SocialChannel::PLATFORM_IG))
            ->setRubric('brand_reels')
            ->setBrand((new Brand())->setTitle('Тест'))
            ->setScriptKey($script->scriptKey)
            ->setScriptJson(json_encode($script->toArray(), JSON_UNESCAPED_UNICODE));

        $this->galleryGenerator()->compose($post, (new SocialRubrics())->get('brand_reels'));

        self::assertStringStartsWith("Чем заменить Zara — ответ внутри.\n\n", (string) $post->getCaption());
    }

    /** Без города h3/h4 остаются без ведущего «{Город}. » — «без города, опустить префикс». */
    public function testGalleryCaptionPrefixDropsMissingCity(): void
    {
        $script = new SlideScript('Имя — в конце.', 'Просто посмотри.', [], 'Тест', 'Российский бренд', 'Сохрани, чтобы не искать.', 'h4.generic|b.none|c.save');

        $post = (new SocialPost())
            ->setChannel((new SocialChannel())->setPlatform(SocialChannel::PLATFORM_IG))
            ->setRubric('brand_gallery')
            ->setBrand(new Brand())
            ->setScriptKey($script->scriptKey)
            ->setScriptJson(json_encode($script->toArray(), JSON_UNESCAPED_UNICODE));

        $this->galleryGenerator()->compose($post, (new SocialRubrics())->get('brand_gallery'));

        self::assertStringStartsWith("Просто посмотри.\n\n", (string) $post->getCaption());
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
