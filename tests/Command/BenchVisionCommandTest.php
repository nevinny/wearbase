<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BenchVisionCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BenchVisionCommandTest extends TestCase
{
    #[DataProvider('pairs')]
    public function testMatchesNormalizesCaseYoAndPunctuation(?string $actual, ?string $expected, bool $expectedMatch): void
    {
        self::assertSame($expectedMatch, BenchVisionCommand::matches($actual, $expected));
    }

    public static function pairs(): array
    {
        return [
            'exact' => ['Футболки', 'Футболки', true],
            'case' => ['футболки', 'Футболки', true],
            'yo' => ['жёлтый', 'желтый', true],
            'substring' => ['Кожаные ботинки', 'ботинки', true],
            'punctuation' => ['синий,', 'синий', true],
            'common prefix' => ['футболка', 'футболки', true],
            'mismatch' => ['красный', 'синий', false],
            'null actual' => [null, 'синий', false],
            'null expected' => ['синий', null, false],
            'empty strings' => ['', '', false],
        ];
    }

    #[DataProvider('colorFamilyPairs')]
    public function testColorFamilyMatchesHandlesShadesAndMultiValueGroundTruth(?string $actual, ?string $expected, bool $expectedMatch): void
    {
        self::assertSame($expectedMatch, BenchVisionCommand::colorFamilyMatches($actual, $expected));
    }

    public static function colorFamilyPairs(): array
    {
        return [
            // Реальные примеры из manifest.jsonl (вещи гардероба, свободный текст).
            'shade with parenthetical clarification' => ['серый', 'серо-голубой (мокрый асфальт)', true],
            'dusty rose is pink' => ['розовый', 'пыльная роза', true],
            'multi-value ground truth intersects one family' => ['зеленый', 'розовый; салатовый; белый; серебристый', true],
            'multi-value ground truth, metallic family' => ['серебристый', 'розовый; салатовый; белый; серебристый', true],
            'dark beige is beige/brown family' => ['коричневый', 'темно-бежевый', true],
            'yo/e spelling does not break the match' => ['зелёный', 'салатовый', true],
            'no overlapping family' => ['синий', 'красный', false],
            'null actual' => [null, 'красный', false],
            'null expected' => ['красный', null, false],
        ];
    }

    #[DataProvider('categoryGroupPairs')]
    public function testCategoryGroupMatchesGroupsByKeyword(?string $actual, ?string $expected, bool $expectedMatch): void
    {
        self::assertSame($expectedMatch, BenchVisionCommand::categoryGroupMatches($actual, $expected));
    }

    public static function categoryGroupPairs(): array
    {
        return [
            'top synonyms' => ['Майка', 'Футболка', true],
            'top vs turtleneck' => ['Лонгслив', 'Водолазка', true],
            'accessory belt' => ['Ремень', 'Ремни для сумок', true],
            'footwear' => ['Туфли', 'Ботильоны', true],
            'different groups' => ['Платье', 'Туфли', false],
            'unknown keyword' => ['Загадочная вещь', 'Футболка', false],
            'null actual' => [null, 'Футболка', false],
        ];
    }

    public function testManifestItemsSkipsRowsWithoutReadableFileAndRespectsLimit(): void
    {
        $existing = tempnam(sys_get_temp_dir(), 'bench_vision_manifest_photo_');
        $manifest = tempnam(sys_get_temp_dir(), 'bench_vision_manifest_');
        try {
            file_put_contents($manifest, implode("\n", [
                json_encode(['id' => 1, 'path' => $existing, 'category' => 'Футболки', 'color' => 'белый', 'ai_prefilled' => true]),
                json_encode(['id' => 2, 'path' => '/no/such/file.jpg', 'category' => 'Джинсы', 'color' => 'синий', 'ai_prefilled' => false]),
                json_encode(['id' => 3, 'path' => $existing, 'category' => 'Платья', 'color' => 'красный', 'ai_prefilled' => null]),
            ]));

            $items = BenchVisionCommand::manifestItems($manifest, 10);

            self::assertSame([1, 3], array_column($items, 'id'));
            self::assertTrue($items[0]['ai_prefilled']);
            self::assertNull($items[1]['ai_prefilled']);

            self::assertCount(1, BenchVisionCommand::manifestItems($manifest, 1));
        } finally {
            unlink($manifest);
            unlink($existing);
        }
    }
}
