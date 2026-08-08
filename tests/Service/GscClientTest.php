<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Gsc\GscClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Search Analytics — общий приватный метод с пагинацией startRow.
 * Токен подкладывается рефлексией: боевой accessToken() ходит в Google, а тесты сети не знают.
 */
class GscClientTest extends TestCase
{
    private function client(MockHttpClient $http): GscClient
    {
        $client = new GscClient($http, __FILE__, 'sc-domain:wearbase.ru');

        $ref = new \ReflectionClass($client);
        $ref->getProperty('token')->setValue($client, 'test-token');
        $ref->getProperty('tokenAt')->setValue($client, microtime(true));

        return $client;
    }

    /** @param array<int,array{keys:list<string>,impressions?:int,clicks?:int,ctr?:float,position?:float}> $rows */
    private function payload(array $rows): MockResponse
    {
        return new MockResponse((string) json_encode(['rows' => $rows]));
    }

    /** Короткая первая страница — ровно ОДИН запрос, лишнюю страницу не тянем. */
    public function testShortFirstPageMakesSingleRequest(): void
    {
        $http = new MockHttpClient([$this->payload([
            ['keys' => ['https://wearbase.ru/ru/brands/a', '2026-07-20'], 'impressions' => 10, 'clicks' => 1, 'ctr' => 0.1, 'position' => 4.44],
        ])]);
        $client = $this->client($http);

        $rows = $client->searchAnalyticsByPage(new \DateTime('2026-07-20'), new \DateTime('2026-07-26'));

        self::assertCount(1, $rows);
        self::assertSame(1, $http->getRequestsCount());
        self::assertSame('https://wearbase.ru/ru/brands/a', $rows[0]['page']);
        self::assertSame('2026-07-20', $rows[0]['date']);
        self::assertSame(10, $rows[0]['impressions']);
        self::assertSame(4.4, $rows[0]['position']); // округление до 0.1 сохранено
    }

    /**
     * Полная страница = данные могли не кончиться → идём за следующей по startRow.
     * Без этого цикла усечение на rowLimit было бы молчаливой потерей строк.
     */
    public function testPaginatesUntilShortPage(): void
    {
        $full = $this->payload([
            ['keys' => ['q1', '2026-07-20'], 'impressions' => 5],
            ['keys' => ['q2', '2026-07-20'], 'impressions' => 4],
        ]);
        $tail = $this->payload([
            ['keys' => ['q3', '2026-07-21'], 'impressions' => 3],
        ]);
        $http   = new MockHttpClient([$full, $tail]);
        $client = $this->client($http);

        $rows = $client->searchAnalyticsByQuery(new \DateTime('2026-07-20'), new \DateTime('2026-07-26'), 2);

        self::assertCount(3, $rows);
        self::assertSame(['q1', 'q2', 'q3'], array_column($rows, 'query'));
        self::assertSame(2, $http->getRequestsCount());

        $first  = json_decode((string) $full->getRequestOptions()['body'], true);
        $second = json_decode((string) $tail->getRequestOptions()['body'], true);
        self::assertSame(0, $first['startRow']);
        self::assertSame(2, $second['startRow']);  // сдвиг ровно на страницу
        self::assertSame(['query', 'date'], $first['dimensions']);
    }

    /** Ровно кратное rowLimit число строк: последняя страница пустая, а не бесконечный цикл. */
    public function testExactMultipleOfRowLimitTerminatesOnEmptyPage(): void
    {
        $http = new MockHttpClient([
            $this->payload([['keys' => ['q1', 'https://wearbase.ru/ru/brands/a'], 'impressions' => 2]]),
            $this->payload([]),
        ]);
        $client = $this->client($http);

        $rows = $client->searchAnalyticsByQueryPage(new \DateTime('2026-07-20'), new \DateTime('2026-07-26'), 1);

        self::assertCount(1, $rows);
        self::assertSame(2, $http->getRequestsCount());
        self::assertSame('q1', $rows[0]['query']);
        self::assertSame('https://wearbase.ru/ru/brands/a', $rows[0]['page']); // page — вторая размерность
    }

    /** rowLimit вне документированного диапазона 1–25000 не должен ломать цикл. */
    public function testRowLimitIsClampedToDocumentedRange(): void
    {
        $page   = $this->payload([]);
        $http   = new MockHttpClient([$page]);
        $client = $this->client($http);

        self::assertSame([], $client->searchAnalyticsByPage(new \DateTime('2026-07-20'), new \DateTime('2026-07-26'), 0));
        self::assertSame(1, $http->getRequestsCount());
        self::assertSame(1, json_decode((string) $page->getRequestOptions()['body'], true)['rowLimit']);
    }

    public function testNotConfiguredWithoutCredentials(): void
    {
        self::assertFalse((new GscClient(new MockHttpClient(), null, 'sc-domain:wearbase.ru'))->isConfigured());
        self::assertFalse((new GscClient(new MockHttpClient(), __FILE__, ''))->isConfigured());
        self::assertTrue((new GscClient(new MockHttpClient(), __FILE__, 'sc-domain:wearbase.ru'))->isConfigured());
    }
}
