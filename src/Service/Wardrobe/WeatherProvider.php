<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * WeatherAPI.com (бесплатный план, 100k запросов/мес, коммерческое использование разрешено):
 * текущая погода для ночного пакетного и интерактивного стилиста гардероба.
 *
 * Пусто в WEATHER_API_KEY = провайдер выключен (ключа пока нет) — isConfigured()/current()
 * отдают null, без ошибок (см. YandexSearchClient::isConfigured() — тот же паттерн).
 *
 * Город зашит константой Moscow — у User и Wardrobe нет поля города вообще (проверено),
 * это заглушка до онбординга города.
 *
 * Fail-soft обязателен: сеть недоступна, 4xx, кривой ответ → null + warning в лог.
 * Подбор образов должен идти без погоды, а не падать.
 */
class WeatherProvider
{
    private const ENDPOINT = 'https://api.weatherapi.com/v1/current.json';

    // Заглушка до онбординга города — у User/Wardrobe поля города нет вообще.
    private const CITY = 'Moscow';

    // Условная граница "ветрено" для выбора одежды (умеренный/свежий ветер, ~4-5 баллов
    // по шкале Бофорта) — ниже неё ветер не перевешивает облачность.
    private const WIND_KPH_THRESHOLD = 30.0;

    /**
     * Коды из https://www.weatherapi.com/docs/weather_conditions.json, разложены по
     * группам WardrobeStylistContextBuilder::WEATHER_CONDITIONS. 1000 (Sunny/Clear) —
     * clear, обрабатывается отдельно. Остальное вне списков ниже — код не распознан.
     */
    private const CLOUDY_CODES = [1003, 1006, 1009, 1012, 1015, 1018, 1021, 1024, 1027, 1030, 1033, 1036, 1039, 1042, 1045, 1048, 1135, 1147];
    private const RAIN_CODES = [1063, 1072, 1087, 1150, 1153, 1168, 1171, 1180, 1183, 1186, 1189, 1192, 1195, 1198, 1201, 1240, 1243, 1246, 1273, 1276];
    private const SNOW_CODES = [1066, 1069, 1114, 1117, 1204, 1207, 1210, 1213, 1216, 1219, 1222, 1225, 1237, 1249, 1252, 1255, 1258, 1261, 1264, 1279, 1282];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
    ) {}

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    /**
     * @return array{0:string,1:string}|null [condition, temperatureBand] — строго из
     *   WardrobeStylistContextBuilder::WEATHER_CONDITIONS/TEMPERATURE_BANDS, либо null.
     */
    public function current(): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        return $this->cache->get('wardrobe_weather_moscow', function (ItemInterface $item): ?array {
            $item->expiresAfter(3600); // 1 час

            return $this->fetch();
        });
    }

    /** @return array{0:string,1:string}|null */
    private function fetch(): ?array
    {
        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'query' => [
                    'key' => $this->apiKey,
                    'q' => self::CITY,
                    'lang' => 'ru',
                ],
                'timeout' => 10,
            ]);
            if ($response->getStatusCode() >= 400) {
                $this->logger->warning('WeatherProvider: ошибка ответа WeatherAPI', ['status' => $response->getStatusCode()]);

                return null;
            }
            $data = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning('WeatherProvider: запрос не удался', ['error' => $e->getMessage()]);

            return null;
        }

        $code = $data['current']['condition']['code'] ?? null;
        $tempC = $data['current']['temp_c'] ?? null;
        $windKph = $data['current']['wind_kph'] ?? null;
        if (!is_int($code) || !is_numeric($tempC)) {
            $this->logger->warning('WeatherProvider: неожиданный формат ответа WeatherAPI');

            return null;
        }

        $condition = $this->mapCondition($code, is_numeric($windKph) ? (float) $windKph : 0.0);
        if ($condition === null) {
            $this->logger->warning('WeatherProvider: нераспознанный код условия', ['code' => $code]);

            return null;
        }

        return [$condition, $this->mapTemperature((float) $tempC)];
    }

    private function mapCondition(int $code, float $windKph): ?string
    {
        $condition = match (true) {
            $code === 1000 => 'clear',
            in_array($code, self::RAIN_CODES, true) => 'rain',
            in_array($code, self::SNOW_CODES, true) => 'snow',
            in_array($code, self::CLOUDY_CODES, true) => 'cloudy',
            default => null,
        };

        // "wind перекрывает облачность" — override только для cloudy, не для rain/snow/clear.
        return $condition === 'cloudy' && $windKph > self::WIND_KPH_THRESHOLD ? 'wind' : $condition;
    }

    private function mapTemperature(float $tempC): string
    {
        return match (true) {
            $tempC < 0 => 'freezing',
            $tempC < 12 => 'cold',
            $tempC < 20 => 'mild',
            $tempC <= 27 => 'warm',
            default => 'hot',
        };
    }
}
