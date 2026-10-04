<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Service\AiUsageTracker;
use App\Service\LlmService;
use App\Repository\WardrobeConsentRepository;
use App\Entity\WardrobeCategory;
use App\Repository\WardrobeCategoryRepository;
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

    #[DataProvider('photoCategories')]
    public function testPhotoUsesActiveCategoryCodesAndCanonicalNames(array $categoryFields, ?string $expected): void
    {
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::once())->method('generateVision')->willReturnCallback(static function (string $prompt) use ($categoryFields): string {
            self::assertStringContainsString('categoryCode', $prompt);
            self::assertStringContainsString('hat', $prompt);
            self::assertStringContainsString('scarf', $prompt);
            return json_encode($categoryFields + ['name' => 'Распознанная вещь', 'season' => 'winter'], JSON_THROW_ON_ERROR);
        });
        $photo = tempnam(sys_get_temp_dir(), 'wardrobe_category_');
        try {
            $result = $this->service($llm, new ArrayAdapter())->suggestFromPhoto($photo);
            self::assertTrue($result['ok']);
            self::assertSame($expected, $result['fields']['category']);
            self::assertArrayNotHasKey('categoryCode', $result['fields']);
            self::assertSame('winter', $result['fields']['season']);
        } finally {
            unlink($photo);
        }
    }

    public static function photoCategories(): array
    {
        return [
            'stable hat beats free label' => [['categoryCode' => 'hat', 'category' => 'Рубашки'], 'Шапка'],
            'stable scarf' => [['categoryCode' => 'scarf', 'category' => 'Шарфик'], 'Шарф'],
            'legacy plural' => [['category' => 'Рубашки'], 'Рубашка'],
            'invalid code' => [['categoryCode' => 'invented-code', 'category' => 'Рубашки'], null],
            'invalid type' => [['categoryCode' => ['hat'], 'category' => 'Шапки'], null],
            'explicit unknown' => [['categoryCode' => null, 'category' => 'Шапки'], null],
            'unrecognized text' => [['category' => 'Модель неизвестного назначения'], null],
        ];
    }

    public function testPhotoCacheChangesWhenActiveCatalogNameChanges(): void
    {
        $cache = new ArrayAdapter();
        $catalog = $this->catalog();
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::exactly(2))->method('generateVision')->willReturn('{"categoryCode":"hat","category":"Шапки"}');
        $photo = tempnam(sys_get_temp_dir(), 'wardrobe_catalog_cache_');
        try {
            self::assertSame('Шапка', $this->service($llm, $cache, catalog: $catalog)->suggestFromPhoto($photo)['fields']['category']);
            $catalog[1]->setName('Головной убор');
            self::assertSame('Головной убор', $this->service($llm, $cache, catalog: $catalog)->suggestFromPhoto($photo)['fields']['category']);
        } finally {
            unlink($photo);
        }
    }

    public function testAttributesFromNamesReturnsFieldsKeyedByIdAndDropsInvalidSeasonAndUnknownIds(): void
    {
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::once())
            ->method('generate')
            ->willReturn(json_encode(['items' => [
                ['id' => 1, 'colorName' => 'пыльная роза', 'materialText' => 'рубчик', 'season' => 'summer'],
                ['id' => 2, 'colorName' => null, 'materialText' => null, 'season' => 'демисезон'],
                // id=999 не входил в запрос — должен быть отброшен, а не создать лишнюю запись.
                ['id' => 999, 'colorName' => 'бордовый', 'materialText' => null, 'season' => 'all'],
            ]], JSON_THROW_ON_ERROR));
        $service = $this->service($llm, new ArrayAdapter());

        $result = $service->suggestAttributesFromNames([
            ['id' => 1, 'name' => 'Облегающая футболка в рубчик цвета пыльной розы', 'category' => 'Футболки', 'materialText' => null],
            ['id' => 2, 'name' => 'Вещь без опознаваемых атрибутов', 'category' => null, 'materialText' => null],
        ]);

        self::assertSame(['colorName' => 'пыльная роза', 'materialText' => 'рубчик', 'season' => 'summer'], $result[1]);
        self::assertSame(['colorName' => null, 'materialText' => null, 'season' => null], $result[2]);
        self::assertArrayNotHasKey(999, $result);
    }

    public function testAttributesFromNamesReturnsEmptyArrayWithoutCallingLlmWhenInputEmpty(): void
    {
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::never())->method('generate');

        self::assertSame([], $this->service($llm, new ArrayAdapter())->suggestAttributesFromNames([]));
    }

    public function testAttributesFromNamesReturnsEmptyArrayOnLlmFailure(): void
    {
        $llm = $this->createMock(LlmService::class);
        $llm->method('generate')->willThrowException(new \RuntimeException('local llm timeout'));

        $result = $this->service($llm, new ArrayAdapter())->suggestAttributesFromNames([
            ['id' => 1, 'name' => 'Платье', 'category' => 'Платья', 'materialText' => null],
        ]);

        self::assertSame([], $result);
    }

    private function service(LlmService $llm, ArrayAdapter $cache, string $model = 'vision-test', ?array $catalog = null): WardrobeAiService
    {
        $categories = $this->getMockBuilder(WardrobeCategoryRepository::class)
            ->disableOriginalConstructor()->onlyMethods(['findActiveTree'])->getMock();
        $categories->method('findActiveTree')->willReturn($catalog ?? $this->catalog());
        return new WardrobeAiService(
            $llm, $this->createStub(WebScraperService::class), $this->createStub(WildberriesAdapter::class),
            $this->createStub(WardrobeAiMeter::class), $this->createStub(AiUsageTracker::class),
            $cache, 'remote-test', true, $model, new NullLogger(),
            $this->createStub(WardrobeConsentRepository::class),
            $categories,
        );
    }

    /** @return WardrobeCategory[] */
    private function catalog(): array
    {
        return array_map(static fn (array $entry): WardrobeCategory => (new WardrobeCategory())->setCode($entry[0])->setName($entry[1]), [
            ['shirt', 'Рубашка'], ['hat', 'Шапка'], ['scarf', 'Шарф'], ['boots', 'Ботинки'],
        ]);
    }
}
