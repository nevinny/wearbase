<?php

declare(strict_types=1);

namespace App\Tests\Service\Keyword;

use App\Service\Keyword\KeywordNicheClassifier;
use App\Service\LlmService;
use PHPUnit\Framework\TestCase;

class KeywordNicheClassifierTest extends TestCase
{
    public function testParsesValidJsonIntoMap(): void
    {
        $llm = $this->createMock(LlmService::class);
        $llm->method('generate')->willReturn(
            '[{"k":"купить платье","v":"in"},{"k":"яндекс погода","v":"off"}]',
        );

        $classifier = new KeywordNicheClassifier($llm);
        $result = $classifier->classifyBatch(['купить платье', 'яндекс погода']);

        self::assertSame(['купить платье' => 'in', 'яндекс погода' => 'off'], $result);
    }

    public function testBrokenChunkIsSkippedNotFatal(): void
    {
        $llm = $this->createMock(LlmService::class);
        $llm->method('generate')->willReturn('это не json вообще');

        $classifier = new KeywordNicheClassifier($llm);
        $result = $classifier->classifyBatch(['джинсы мужские']);

        self::assertSame([], $result);
    }

    public function testSecondChunkStillProcessedWhenFirstChunkBroken(): void
    {
        // Первый чанк (30 фраз, ровно CHUNK_SIZE) — LLM вернёт битый ответ;
        // второй чанк (1 фраза) — валидный. Оба вызова изолированы.
        $keywords = array_map(static fn (int $i) => "фраза $i", range(1, 30));
        $keywords[] = 'тренч';

        $llm = $this->createMock(LlmService::class);
        $llm->method('generate')->willReturnOnConsecutiveCalls(
            'битый ответ без json',
            '[{"k":"тренч","v":"in"}]',
        );

        $classifier = new KeywordNicheClassifier($llm);
        $result = $classifier->classifyBatch($keywords);

        self::assertSame(['тренч' => 'in'], $result);
    }
}
