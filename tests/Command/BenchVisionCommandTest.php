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
}
