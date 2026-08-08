<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\AiUsageLog;
use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Service\AiUsageTracker;
use App\Service\LlmService;
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

    private function item(int $id, string $name, string $category): WardrobeItem
    {
        $item = (new WardrobeItem())->setName($name)->setCategory($category);
        $property = new \ReflectionProperty(WardrobeItem::class, 'id');
        $property->setValue($item, $id);

        return $item;
    }
}
