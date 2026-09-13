<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Service\AiUsageTracker;
use App\Service\LlmService;
use App\Service\Wardrobe\WardrobeAiService;
use App\Service\Wardrobe\WildberriesAdapter;
use App\Service\WardrobeAiMeter;
use App\Service\WebScraperService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class WardrobeAiServiceTest extends TestCase
{
    #[DataProvider('seasons')]
    public function testPhotoAttributesAreStructuredAndUnknownValuesStayNull(mixed $season, ?string $expected): void
    {
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::once())->method('generateVision')->willReturn(json_encode([
            'name' => 'Белая рубашка', 'category' => 'Рубашки', 'color' => ' белый ',
            'material' => null, 'season' => $season, 'size' => ['invented'],
            'type' => 'свободный крой', 'confidence' => 'high',
        ], JSON_THROW_ON_ERROR));
        $service = $this->service($llm, new ArrayAdapter());
        $photo = tempnam(sys_get_temp_dir(), 'wardrobe_attributes_');
        try {
            $result = $service->suggestFromPhoto($photo);
            self::assertTrue($result['ok']);
            self::assertSame('белый', $result['fields']['colorName']);
            self::assertNull($result['fields']['materialText']);
            self::assertNull($result['fields']['size']);
            self::assertSame($expected, $result['fields']['season']);
            self::assertSame('Фасон: свободный крой', $result['fields']['notes']);
            self::assertSame('vision-test', $result['model']);
            self::assertSame(WardrobeAiService::PHOTO_SCHEMA_VERSION, $result['schemaVersion']);
            self::assertSame($result, $service->suggestFromPhoto($photo));
        } finally {
            unlink($photo);
        }
    }

    public static function seasons(): array
    {
        return [
            ['summer', 'summer'], ['лето', 'summer'], ['winter', 'winter'],
            ['всесезон', 'all'], ['демисезон', null], ['unknown', null], [null, null], [[], null],
        ];
    }

    public function testCacheDoesNotReuseOldSchemaOrAnotherModel(): void
    {
        $photo = tempnam(sys_get_temp_dir(), 'wardrobe_cache_');
        $cache = new ArrayAdapter();
        $legacy = $cache->getItem('wardrobe_ai_photo_'.sha1_file($photo));
        $legacy->set(['ok' => true, 'fields' => ['notes' => 'old result']]);
        $cache->save($legacy);
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::exactly(2))->method('generateVision')->willReturn('{"color":"синий"}');
        try {
            self::assertSame('синий', $this->service($llm, $cache)->suggestFromPhoto($photo)['fields']['colorName']);
            self::assertSame('other', $this->service($llm, $cache, 'other')->suggestFromPhoto($photo)['model']);
        } finally {
            unlink($photo);
        }
    }

    private function service(LlmService $llm, ArrayAdapter $cache, string $model = 'vision-test'): WardrobeAiService
    {
        return new WardrobeAiService(
            $llm, $this->createStub(WebScraperService::class), $this->createStub(WildberriesAdapter::class),
            $this->createStub(WardrobeAiMeter::class), $this->createStub(AiUsageTracker::class),
            $cache, 'remote-test', true, $model, new NullLogger(),
        );
    }
}
