<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\CommentKeyword;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CommentKeywordTest extends TestCase
{
    #[DataProvider('cases')]
    public function testMatches(string $text, string $keyword, bool $expected): void
    {
        self::assertSame($expected, CommentKeyword::matches($text, $keyword));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function cases(): iterable
    {
        yield 'точное' => ['размер', 'размер', true];
        yield 'регистр' => ['РАЗМЕР', 'размер', true];
        yield 'кавычки и знаки' => ['«Размер»!!! 🙏', 'размер', true];
        yield 'в предложении' => ['Пришлите, пожалуйста, размер, очень надо', 'размер', true];
        yield 'ё = е' => ['ПЕРЕЧЕНЬ', 'перечень', true];
        yield 'ё в слове' => ['Всё', 'все', true];
        yield 'ё в ключе' => ['все', 'всё', true];
        yield 'форма слова — не совпадение' => ['размеры', 'размер', false];
        yield 'слово внутри другого' => ['безразмерный', 'размер', false];
        yield 'через дефис — отдельные слова' => ['мой-размер', 'размер', true];
        yield 'пусто' => ['', 'размер', false];
        yield 'пустое слово' => ['размер', '  ', false];
        yield 'латиница не совпадает с кириллицей' => ['pазмер', 'размер', false];
    }
}
