<?php

declare(strict_types=1);

namespace App\Tests\Unit\ValueObject;

use App\ValueObject\MoneyAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyAmountTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function testNormalizesExactDecimal(string $input, string $expected, int $minor): void
    {
        self::assertSame($expected, MoneyAmount::normalize($input));
        self::assertSame($minor, MoneyAmount::toMinor($input));
        self::assertSame($expected, MoneyAmount::fromMinor($minor));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function validAmounts(): iterable
    {
        yield 'zero' => ['0', '0.00', 0];
        yield 'kopeck' => ['0.01', '0.01', 1];
        yield 'one decimal' => ['12.3', '12.30', 1230];
        yield 'leading zeros' => ['0012.30', '12.30', 1230];
        yield 'maximum' => ['9999999999.99', '9999999999.99', 999999999999];
    }

    #[DataProvider('invalidAmounts')]
    public function testRejectsInvalidDomainAmount(string $amount): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MoneyAmount::normalize($amount);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAmounts(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'three decimals' => ['1.001'];
        yield 'scientific' => ['1e3'];
        yield 'infinity' => ['INF'];
        yield 'text' => ['free'];
        yield 'too large' => ['10000000000.00'];
    }
}
