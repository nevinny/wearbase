<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TextChunker;
use PHPUnit\Framework\TestCase;

class TextChunkerTest extends TestCase
{
    private TextChunker $chunker;

    protected function setUp(): void
    {
        $this->chunker = new TextChunker();
    }

    public function testShortTextSingleChunk(): void
    {
        $chunks = $this->chunker->chunk('Короткий текст о бренде.');
        self::assertCount(1, $chunks);
    }

    public function testEmptyTextNoChunks(): void
    {
        self::assertSame([], $this->chunker->chunk(''));
        self::assertSame([], $this->chunker->chunk('   '));
    }

    /** Регрессия: хвост ≤ OVERLAP давал шаг 1 символ → сотни чанков-дублей. */
    public function testLongTextNoTailExplosion(): void
    {
        // 10 000 симв ≈ 8 чанков при stride 1260; до фикса было ~487
        $text = mb_substr(str_repeat('Бренд выпускает худи и футболки из плотного хлопка. ', 250), 0, 10000);
        $chunks = $this->chunker->chunk($text);

        self::assertGreaterThan(3, count($chunks));
        self::assertLessThan(15, count($chunks), 'хвостовое зацикливание: чанков слишком много');
    }

    /** Перекрытие: конец чанка N встречается в начале чанка N+1. */
    public function testChunksOverlap(): void
    {
        $text = str_repeat('Слово ', 1000);
        $chunks = $this->chunker->chunk($text);
        self::assertGreaterThan(1, count($chunks));

        $tail = mb_substr($chunks[0], -100);
        self::assertStringContainsString(mb_substr($tail, 0, 50), $chunks[1]);
    }
}
