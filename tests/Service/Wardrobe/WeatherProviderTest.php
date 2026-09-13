<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Service\Wardrobe\WeatherProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WeatherProviderTest extends TestCase
{
    public function testNotConfiguredReturnsNullWithoutHttpCall(): void
    {
        $http = new MockHttpClient();
        $provider = new WeatherProvider($http, new ArrayAdapter(), new NullLogger(), '');

        self::assertFalse($provider->isConfigured());
        self::assertNull($provider->current());
        self::assertSame(0, $http->getRequestsCount());
    }

    #[DataProvider('temperatureBoundaries')]
    public function testTemperatureBoundaries(float $tempC, string $expectedBand): void
    {
        $provider = $this->provider($this->weatherApiResponse(1000, $tempC));

        self::assertSame(['clear', $expectedBand], $provider->current());
    }

    public static function temperatureBoundaries(): array
    {
        return [
            'well below freezing' => [-10.0, 'freezing'],
            'just below zero' => [-0.1, 'freezing'],
            'zero is cold, not freezing' => [0.0, 'cold'],
            'just below cold/mild boundary' => [11.9, 'cold'],
            'twelve is mild, not cold' => [12.0, 'mild'],
            'just below mild/warm boundary' => [19.9, 'mild'],
            'twenty is warm, not mild' => [20.0, 'warm'],
            'twenty seven is still warm' => [27.0, 'warm'],
            'just above twenty seven is hot' => [27.1, 'hot'],
            'well above hot' => [35.0, 'hot'],
        ];
    }

    #[DataProvider('conditionCodes')]
    public function testConditionCodeGroups(int $code, string $expectedCondition): void
    {
        $provider = $this->provider($this->weatherApiResponse($code, 15.0));

        self::assertSame([$expectedCondition, 'mild'], $provider->current());
    }

    public static function conditionCodes(): array
    {
        return [
            'sunny -> clear' => [1000, 'clear'],
            'plain cloudy -> cloudy' => [1006, 'cloudy'],
            'fog -> cloudy (облачно и туман)' => [1135, 'cloudy'],
            'haze -> cloudy' => [1012, 'cloudy'],
            'light drizzle -> rain (морось)' => [1153, 'rain'],
            'light rain -> rain' => [1183, 'rain'],
            'thundery outbreaks -> rain (гроза)' => [1087, 'rain'],
            'light snow -> snow' => [1213, 'snow'],
            'light sleet -> snow (мокрый снег/лёд)' => [1204, 'snow'],
            'ice pellets -> snow (град/лёд)' => [1237, 'snow'],
        ];
    }

    public function testUnrecognizedCodeReturnsNull(): void
    {
        $provider = $this->provider($this->weatherApiResponse(9999, 15.0));

        self::assertNull($provider->current());
    }

    public function testHighWindOverridesCloudyToWind(): void
    {
        $provider = $this->provider($this->weatherApiResponse(1006, 15.0, windKph: 40.0));

        self::assertSame(['wind', 'mild'], $provider->current());
    }

    public function testLowWindKeepsCloudy(): void
    {
        $provider = $this->provider($this->weatherApiResponse(1006, 15.0, windKph: 5.0));

        self::assertSame(['cloudy', 'mild'], $provider->current());
    }

    /** Ветер "перекрывает облачность", а не дождь/снег/ясно. */
    public function testHighWindDoesNotOverrideRain(): void
    {
        $provider = $this->provider($this->weatherApiResponse(1183, 15.0, windKph: 60.0));

        self::assertSame(['rain', 'mild'], $provider->current());
    }

    public function testHttpErrorFailsSoft(): void
    {
        $provider = $this->provider(new MockResponse('service unavailable', ['http_code' => 503]));

        self::assertNull($provider->current());
    }

    public function testTransportErrorFailsSoft(): void
    {
        $provider = $this->provider(new MockResponse('', ['error' => 'Connection refused']));

        self::assertNull($provider->current());
    }

    public function testMalformedResponseFailsSoft(): void
    {
        $provider = $this->provider(new MockResponse(json_encode(['current' => ['temp_c' => 'not-a-number']])));

        self::assertNull($provider->current());
    }

    public function testResultIsCachedForOneHour(): void
    {
        $http = new MockHttpClient([$this->weatherApiResponse(1000, 5.0)]);
        $provider = new WeatherProvider($http, new ArrayAdapter(), new NullLogger(), 'test-key');

        self::assertSame(['clear', 'cold'], $provider->current());
        self::assertSame(['clear', 'cold'], $provider->current());
        self::assertSame(1, $http->getRequestsCount());
    }

    private function provider(MockResponse $response): WeatherProvider
    {
        return new WeatherProvider(new MockHttpClient($response), new ArrayAdapter(), new NullLogger(), 'test-key');
    }

    private function weatherApiResponse(int $code, float $tempC, float $windKph = 0.0): MockResponse
    {
        return new MockResponse(json_encode([
            'current' => [
                'temp_c' => $tempC,
                'wind_kph' => $windKph,
                'condition' => ['text' => 'test', 'code' => $code],
            ],
        ], JSON_THROW_ON_ERROR));
    }
}
