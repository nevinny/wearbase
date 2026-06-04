<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Keyword\WordstatClient;
use App\Service\Keyword\WordstatQuotaException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class WordstatClientTest extends TestCase
{
    public function testParsesResultsAndAssociations(): void
    {
        $client = new WordstatClient(new MockHttpClient(new MockResponse(json_encode([
            'results' => [
                ['phrase' => 'бренд одежда', 'count' => 120],
                ['phrase' => 'бренд купить', 'count' => '45'],
            ],
            'associations' => [
                ['phrase' => 'похожий запрос', 'count' => 7],
            ],
        ]))), 'test-key');

        $rows = $client->keywordsFor('бренд');

        self::assertCount(3, $rows);
        self::assertSame(['keyword' => 'бренд одежда', 'type' => 'origin', 'monthlyShows' => 120], $rows[0]);
        self::assertSame('related', $rows[2]['type']);
        self::assertSame(45, $rows[1]['monthlyShows']); // строковый count нормализуется
    }

    public function testLimitRespected(): void
    {
        $rows = array_map(static fn(int $i) => ['phrase' => "фраза {$i}", 'count' => $i], range(1, 50));
        $client = new WordstatClient(new MockHttpClient(new MockResponse(json_encode(['results' => $rows]))), 'k');

        self::assertCount(10, $client->keywordsFor('x', 10));
    }

    /** Квота 100/час: 429 и сигнатуры RESOURCE_EXHAUSTED — отдельное исключение, не пустой массив. */
    public function testQuotaThrows(): void
    {
        $client = new WordstatClient(new MockHttpClient(new MockResponse('quota limit exceed', ['http_code' => 429])), 'k');

        $this->expectException(WordstatQuotaException::class);
        $client->keywordsFor('x');
    }

    public function testQuotaSignatureInBodyThrows(): void
    {
        $client = new WordstatClient(new MockHttpClient(new MockResponse('{"error":"RESOURCE_EXHAUSTED"}', ['http_code' => 200])), 'k');

        $this->expectException(WordstatQuotaException::class);
        $client->keywordsFor('x');
    }

    /** Прочие HTTP-ошибки — пустой массив (бренд просто пропускается). */
    public function testHttpErrorReturnsEmpty(): void
    {
        $client = new WordstatClient(new MockHttpClient(new MockResponse('boom', ['http_code' => 500])), 'k');

        self::assertSame([], $client->keywordsFor('x'));
    }

    public function testNotConfiguredReturnsEmpty(): void
    {
        $client = new WordstatClient(new MockHttpClient(), '');

        self::assertSame([], $client->keywordsFor('x'));
    }

    /** Мусорные строки (без phrase) пропускаются. */
    public function testMalformedRowsSkipped(): void
    {
        $client = new WordstatClient(new MockHttpClient(new MockResponse(json_encode([
            'results' => [['count' => 5], 'строка', ['phrase' => '  '], ['phrase' => 'ок', 'count' => 1]],
        ]))), 'k');

        $rows = $client->keywordsFor('x');
        self::assertCount(1, $rows);
        self::assertSame('ок', $rows[0]['keyword']);
    }
}
