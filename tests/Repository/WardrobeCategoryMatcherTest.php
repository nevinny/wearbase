<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\WardrobeCategory;
use App\Repository\WardrobeCategoryRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WardrobeCategoryMatcherTest extends TestCase
{
    #[DataProvider('values')]
    public function testMatchesOnlyActiveCodesNamesAndKnownLegacyAliases(string $value, ?string $expected): void
    {
        $catalog = [
            (new WardrobeCategory())->setCode('shirt')->setName('Рубашка'),
            (new WardrobeCategory())->setCode('hat')->setName('Шапка'),
            (new WardrobeCategory())->setCode('scarf')->setName('Шарф'),
            (new WardrobeCategory())->setCode('retired')->setName('Устаревший тип')->setActive(false),
        ];
        $repository = $this->getMockBuilder(WardrobeCategoryRepository::class)->disableOriginalConstructor()->onlyMethods(['findActiveTree'])->getMock();
        self::assertSame($expected, $repository->resolveActive($value, $catalog)?->getCode());
    }

    public static function values(): array
    {
        return [
            [' SHIRT ', 'shirt'], [' рубашка ', 'shirt'], ['РУБАШКИ', 'shirt'],
            ['Шапки', 'hat'], ['Шарфы', 'scarf'], ['hat', 'hat'],
            ['retired', null], ['Устаревший тип', null], ['', null],
            ['Зимняя шапка с помпоном', null], ['Неизвестная категория', null],
        ];
    }
}
