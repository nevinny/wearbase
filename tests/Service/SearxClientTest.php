<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SearxClient;
use App\Service\SearxUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SearxClientTest extends TestCase
{
    public function testParsesResults(): void
    {
        $client = new SearxClient(new MockHttpClient(new MockResponse(json_encode([
            'results' => [
                ['url' => 'https://a.ru', 'title' => 'A', 'content' => 'sn'],
                ['url' => '', 'title' => 'битый'],
                ['url' => 'https://b.ru', 'title' => 'B', 'content' => ''],
            ],
        ]))), 'http://searx');

        $rows = $client->search('q');
        self::assertCount(2, $rows);
        self::assertSame('https://a.ru', $rows[0]['url']);
    }

    /**
     * Canary-логика (инцидент 2026-06-04): пустая выдача при suspended-движках —
     * это «поиск лежит», только если И canary пуст. Иначе honest-ноль.
     */
    public function testEmptyWithDeadEnginesAndDeadCanaryThrows(): void
    {
        $dead = json_encode(['results' => [], 'unresponsive_engines' => [['google', 'CAPTCHA']]]);
        // 1-й ответ — запрос, 2-й — canary (тоже пустой)
        $client = new SearxClient(new MockHttpClient([new MockResponse($dead), new MockResponse($dead)]), 'http://searx');

        $this->expectException(SearxUnavailableException::class);
        $client->search('нишевый бренд');
    }

    public function testEmptyButCanaryAliveReturnsHonestZero(): void
    {
        $empty = json_encode(['results' => [], 'unresponsive_engines' => [['yandex', 'parsing error']]]);
        $alive = json_encode(['results' => [['url' => 'https://wb.ru', 'title' => 'x', 'content' => '']]]);
        $client = new SearxClient(new MockHttpClient([new MockResponse($empty), new MockResponse($alive)]), 'http://searx');

        self::assertSame([], $client->search('нишевый бренд')); // честный ноль, без исключения
    }

    public function testHttpErrorThrowsUnavailable(): void
    {
        $client = new SearxClient(new MockHttpClient(new MockResponse('err', ['http_code' => 502])), 'http://searx');

        $this->expectException(SearxUnavailableException::class);
        $client->search('q');
    }

    public function testNotConfiguredReturnsEmpty(): void
    {
        $client = new SearxClient(new MockHttpClient(), '');

        self::assertSame([], $client->search('q'));
    }
}
