<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Source;

use App\Entity\Brand;
use App\Entity\SocialPost;
use App\Service\BrandRagService;
use App\Service\LlmService;
use App\Service\Social\Source\BrandDescriptionCaptionSource;
use App\Service\Social\Source\FounderStoryCaptionSource;
use PHPUnit\Framework\TestCase;

class FounderStoryCaptionSourceTest extends TestCase
{
    private function llmEchoingPrompt(): LlmService
    {
        $llm = $this->createMock(LlmService::class);
        $llm->method('generate')->willReturnCallback(static fn (string $prompt): string => $prompt);

        return $llm;
    }

    private function post(Brand $brand): SocialPost
    {
        return (new SocialPost())->setRubric('brand_week')->setBrand($brand);
    }

    /** Gate RAG пройден (есть context) → промпт содержит выдержки и имя бренда. */
    public function testUsesRagContextWhenGatePassed(): void
    {
        $llm = $this->llmEchoingPrompt();
        $rag = $this->createMock(BrandRagService::class);
        $rag->method('retrieve')->willReturn(['context' => 'Основатель начал шить куртки в гараже.', 'score' => 0.7, 'chunks' => 4]);

        $source = new FounderStoryCaptionSource($llm, $rag, new BrandDescriptionCaptionSource($llm));
        $brand = (new Brand())->setTitle('Гараж');

        $body = $source->body($this->post($brand));

        self::assertStringContainsString('Основатель начал шить куртки в гараже.', $body);
        self::assertStringContainsString('Гараж', $body);
    }

    /** Gate НЕ пройден (context=null) → фолбэк на описание бренда. */
    public function testFallsBackToDescriptionWhenGateFails(): void
    {
        $llm = $this->llmEchoingPrompt();
        $rag = $this->createMock(BrandRagService::class);
        $rag->method('retrieve')->willReturn(['context' => null, 'score' => null, 'chunks' => 0]);

        $source = new FounderStoryCaptionSource($llm, $rag, new BrandDescriptionCaptionSource($llm));
        $brand = (new Brand())->setTitle('Гараж')->setDescription('Бренд из Москвы шьёт куртки.');

        $body = $source->body($this->post($brand));

        self::assertStringContainsString('Бренд из Москвы шьёт куртки.', $body);
    }

    /** Qdrant недоступен (исключение) → тот же фолбэк, пост не должен провалиться. */
    public function testFallsBackToDescriptionWhenRagThrows(): void
    {
        $llm = $this->llmEchoingPrompt();
        $rag = $this->createMock(BrandRagService::class);
        $rag->method('retrieve')->willThrowException(new \RuntimeException('Qdrant unreachable'));

        $source = new FounderStoryCaptionSource($llm, $rag, new BrandDescriptionCaptionSource($llm));
        $brand = (new Brand())->setTitle('Гараж')->setDescription('Бренд из Москвы шьёт куртки.');

        $body = $source->body($this->post($brand));

        self::assertStringContainsString('Бренд из Москвы шьёт куртки.', $body);
    }
}
