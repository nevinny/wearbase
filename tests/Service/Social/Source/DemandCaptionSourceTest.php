<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Source;

use App\Entity\Brand;
use App\Entity\BrandKeyword;
use App\Entity\SocialPost;
use App\Repository\BrandKeywordRepository;
use App\Service\LlmService;
use App\Service\Social\Source\BrandDescriptionCaptionSource;
use App\Service\Social\Source\DemandCaptionSource;
use PHPUnit\Framework\TestCase;

class DemandCaptionSourceTest extends TestCase
{
    private function llmEchoingPrompt(): LlmService
    {
        $llm = $this->createMock(LlmService::class);
        $llm->method('generate')->willReturnCallback(static fn (string $prompt): string => $prompt);

        return $llm;
    }

    private function post(Brand $brand): SocialPost
    {
        return (new SocialPost())->setRubric('demand')->setBrand($brand);
    }

    /** Есть топ-фраза → промпт содержит фразу, показы и имя бренда. */
    public function testUsesTopKeywordWhenPresent(): void
    {
        $llm = $this->llmEchoingPrompt();
        $keyword = (new BrandKeyword())->setKeyword('куртка оверсайз мужская')->setMonthlyShows(3200);
        $keywords = $this->createMock(BrandKeywordRepository::class);
        $keywords->method('findTopByBrand')->willReturn($keyword);

        $source = new DemandCaptionSource($llm, $keywords, new BrandDescriptionCaptionSource($llm));
        $brand = (new Brand())->setTitle('Гараж')->setDescription('Шьём мужские куртки оверсайз в Москве.');

        $body = $source->body($this->post($brand));

        self::assertStringContainsString('куртка оверсайз мужская', $body);
        self::assertStringContainsString('3200', $body);
        self::assertStringContainsString('Гараж', $body);
    }

    /** Нет ключевиков у бренда → фолбэк на описание. */
    public function testFallsBackToDescriptionWhenNoKeywords(): void
    {
        $llm = $this->llmEchoingPrompt();
        $keywords = $this->createMock(BrandKeywordRepository::class);
        $keywords->method('findTopByBrand')->willReturn(null);

        $source = new DemandCaptionSource($llm, $keywords, new BrandDescriptionCaptionSource($llm));
        $brand = (new Brand())->setTitle('Гараж')->setDescription('Шьём мужские куртки оверсайз в Москве.');

        $body = $source->body($this->post($brand));

        self::assertStringContainsString('Шьём мужские куртки оверсайз в Москве.', $body);
        self::assertStringNotContainsString('Яндекс', $body);
    }
}
