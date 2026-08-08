<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\AiUsageLog;
use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Service\AiUsageTracker;
use App\Service\LlmService;
use App\Service\Wardrobe\WardrobeAiException;
use App\Service\Wardrobe\WardrobeOutfitService;
use App\Service\WardrobeAiMeter;
use PHPUnit\Framework\TestCase;

class WardrobeOutfitServiceTest extends TestCase
{
    public function testSuggestKeepsOnlyExistingItemIds(): void
    {
        $shirt = $this->item(11, 'Белая рубашка', 'Рубашки');
        $trousers = $this->item(12, 'Синие брюки', 'Брюки');
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::once())->method('generate')->willReturn(
            '{"outfits":[{"title":"В офис","explanation":"Спокойное сочетание","item_ids":[11,12,999]}]}',
        );
        $meter = $this->createMock(WardrobeAiMeter::class);
        $meter->method('allowed')->willReturn(true);
        $meter->expects(self::once())->method('record');
        $tracker = $this->createMock(AiUsageTracker::class);
        $user = new User();
        $tracker->expects(self::once())->method('record')->with($user, AiUsageLog::FEATURE_WARDROBE_OUTFIT);

        $result = (new WardrobeOutfitService($llm, $meter, $tracker, 'test-model'))
            ->suggest($user, [$shirt, $trousers], 'В офис');

        self::assertCount(1, $result);
        self::assertSame([$shirt, $trousers], $result[0]['items']);
    }

    public function testSuggestRequiresAtLeastTwoItems(): void
    {
        $service = new WardrobeOutfitService(
            $this->createStub(LlmService::class),
            $this->createStub(WardrobeAiMeter::class),
            $this->createStub(AiUsageTracker::class),
            'test-model',
        );

        $this->expectException(\DomainException::class);
        $service->suggest(new User(), [$this->item(1, 'Рубашка', 'Рубашки')], 'На прогулку');
    }

    public function testSuggestStopsBeforeLlmWhenDailyLimitIsExhausted(): void
    {
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::never())->method('generate');
        $meter = $this->createMock(WardrobeAiMeter::class);
        $meter->method('allowed')->willReturn(false);
        $meter->expects(self::never())->method('record');
        $service = new WardrobeOutfitService($llm, $meter, $this->createStub(AiUsageTracker::class), 'test-model');

        $this->expectException(WardrobeAiException::class);
        $this->expectExceptionMessage('Дневной лимит');
        $service->suggest(new User(), [$this->item(1, 'Рубашка', 'Рубашки'), $this->item(2, 'Брюки', 'Брюки')], 'В офис');
    }

    public function testSuggestRejectsInvalidJson(): void
    {
        $llm = $this->createStub(LlmService::class);
        $llm->method('generate')->willReturn('Сочетайте рубашку и брюки');
        $meter = $this->createStub(WardrobeAiMeter::class);
        $meter->method('allowed')->willReturn(true);
        $service = new WardrobeOutfitService($llm, $meter, $this->createStub(AiUsageTracker::class), 'test-model');

        $this->expectException(WardrobeAiException::class);
        $this->expectExceptionMessage('Не удалось собрать образы');
        $service->suggest(new User(), [$this->item(1, 'Рубашка', 'Рубашки'), $this->item(2, 'Брюки', 'Брюки')], 'В офис');
    }

    public function testSuggestDropsOutfitsWithLessThanTwoKnownItems(): void
    {
        $llm = $this->createStub(LlmService::class);
        $llm->method('generate')->willReturn('{"outfits":[{"title":"Фантазия","item_ids":[1,999]}]}');
        $meter = $this->createStub(WardrobeAiMeter::class);
        $meter->method('allowed')->willReturn(true);
        $service = new WardrobeOutfitService($llm, $meter, $this->createStub(AiUsageTracker::class), 'test-model');

        $this->expectException(WardrobeAiException::class);
        $this->expectExceptionMessage('Модель не нашла');
        $service->suggest(new User(), [$this->item(1, 'Рубашка', 'Рубашки'), $this->item(2, 'Брюки', 'Брюки')], 'На прогулку');
    }

    public function testSuggestAcceptsMarkdownWrapperAndNumericStringIds(): void
    {
        $shirt = $this->item(1, 'Рубашка', 'Рубашки');
        $trousers = $this->item(2, 'Брюки', 'Брюки');
        $llm = $this->createStub(LlmService::class);
        $llm->method('generate')->willReturn("```json\n{\"outfits\":[{\"title\":\"База\",\"item_ids\":[\"1\",\"2\"]}]}\n```");
        $meter = $this->createStub(WardrobeAiMeter::class);
        $meter->method('allowed')->willReturn(true);
        $service = new WardrobeOutfitService($llm, $meter, $this->createStub(AiUsageTracker::class), 'test-model');

        $result = $service->suggest(new User(), [$shirt, $trousers], '');

        self::assertSame([$shirt, $trousers], $result[0]['items']);
        self::assertSame('База', $result[0]['title']);
    }

    private function item(int $id, string $name, string $category): WardrobeItem
    {
        $item = (new WardrobeItem())->setName($name)->setCategory($category);
        $property = new \ReflectionProperty(WardrobeItem::class, 'id');
        $property->setValue($item, $id);

        return $item;
    }
}
