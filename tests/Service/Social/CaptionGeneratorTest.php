<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Service\LlmService;
use App\Service\Social\CaptionGenerator;
use App\Service\Social\Source\BrandDescriptionCaptionSource;
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
}
