<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\InstagramInsights;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * InstagramInsights: единственная ответственность — HTTP GET на Graph API insights +
 * разбор ответа. Наборы метрик по типу медиа проверены живьём (см. задачу/CHANGELOG задачи).
 */
class InstagramInsightsTest extends TestCase
{
    public function testReelsRequestsExtendedMetricSetAndParsesValues(): void
    {
        /** @var list<array{method: string, url: string}> $requests */
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url];

            return new MockResponse(json_encode([
                'data' => [
                    ['name' => 'reach', 'values' => [['value' => 39]]],
                    ['name' => 'likes', 'values' => [['value' => 5]]],
                    ['name' => 'comments', 'values' => [['value' => 1]]],
                    ['name' => 'shares', 'values' => [['value' => 2]]],
                    ['name' => 'saved', 'values' => [['value' => 3]]],
                    ['name' => 'views', 'values' => [['value' => 44]]],
                    ['name' => 'total_interactions', 'values' => [['value' => 11]]],
                    ['name' => 'ig_reels_avg_watch_time', 'values' => [['value' => 3143]]],
                ],
            ]));
        });

        $insights = new InstagramInsights($client);
        $values = $insights->fetch('17958698723984727', true, 'test-token');

        self::assertCount(1, $requests);
        self::assertSame('GET', $requests[0]['method']);
        self::assertStringContainsString('/17958698723984727/insights', $requests[0]['url']);
        self::assertStringContainsString(
            'metric=' . rawurlencode('reach,likes,comments,shares,saved,views,total_interactions,ig_reels_avg_watch_time'),
            $requests[0]['url'],
        );
        self::assertStringContainsString('access_token=test-token', $requests[0]['url']);

        self::assertSame([
            'reach'                    => 39,
            'likes'                    => 5,
            'comments'                 => 1,
            'shares'                   => 2,
            'saved'                    => 3,
            'views'                    => 44,
            'total_interactions'       => 11,
            'ig_reels_avg_watch_time'  => 3143,
        ], $values);
    }

    public function testNonReelsRequestsDefaultMetricSetWithoutViewsOrWatchTime(): void
    {
        /** @var list<array{method: string, url: string}> $requests */
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url];

            return new MockResponse(json_encode([
                'data' => [
                    ['name' => 'reach', 'values' => [['value' => 1]]],
                    ['name' => 'likes', 'values' => [['value' => 0]]],
                    ['name' => 'comments', 'values' => [['value' => 0]]],
                    ['name' => 'shares', 'values' => [['value' => 0]]],
                    ['name' => 'saved', 'values' => [['value' => 0]]],
                    ['name' => 'total_interactions', 'values' => [['value' => 1]]],
                ],
            ]));
        });

        $insights = new InstagramInsights($client);
        $values = $insights->fetch('18138911335569367', false, 'test-token');

        self::assertStringContainsString(
            'metric=' . rawurlencode('reach,likes,comments,shares,saved,total_interactions'),
            $requests[0]['url'],
        );
        self::assertArrayNotHasKey('views', $values);
        self::assertArrayNotHasKey('ig_reels_avg_watch_time', $values);
        self::assertSame(1, $values['reach']);
    }

    public function testGraphApiErrorIsNotSwallowed(): void
    {
        $client = new MockHttpClient(fn (): MockResponse => new MockResponse(json_encode([
            'error' => ['message' => 'Invalid OAuth access token', 'code' => 190],
        ])));

        $insights = new InstagramInsights($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid OAuth access token.*190/');

        $insights->fetch('123', false, 'bad-token');
    }
}
