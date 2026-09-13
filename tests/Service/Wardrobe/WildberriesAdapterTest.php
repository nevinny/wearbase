<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Service\Wardrobe\WildberriesAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * fetchCard() — карточка характеристик (basket-шард JSON), отдельно от fetch()
 * (v4/detail характеристик не отдаёт). Перебор шардов идентичен findImageUrl():
 * все 30 запросов уходят до чтения любого ответа — MockHttpClient дожидается ровно
 * BASKET_HOSTS_MAX ответов на один fetchCard().
 */
final class WildberriesAdapterTest extends TestCase
{
    private const URL = 'https://www.wildberries.ru/catalog/13578826/detail.aspx';

    public function testFetchCardMapsOptionsFromBothArraysAndTolerantlyParsesRawControlChars(): void
    {
        // Реальный образец WB: сырой перевод строки внутри значения ("Уход за вещами") —
        // стандартный json_decode() падает на нём с "Control character error".
        $cardJson = '{"options":[{"name":"Состав","value":"вискоза 80%; шелк 20%"},'
            .'{"name":"Фактура материала","value":"шелковый"}],'
            .'"grouped_options":[{"options":[{"name":"Цвет","value":"тускло-сиреневый; фиолетовый"},'
            .'{"name":"Страна производства","value":"Россия"},'
            .'{"name":"Уход за вещами","value":"гладить'."\n".'на низкой температуре"}]}]}';

        $responses = array_fill(0, 29, new MockResponse('', ['http_code' => 404]));
        array_unshift($responses, new MockResponse($cardJson, ['http_code' => 200]));
        $adapter = new WildberriesAdapter(new MockHttpClient($responses), 'test-agent');

        $result = $adapter->fetchCard(self::URL);

        self::assertSame([
            'materialText' => 'вискоза 80%; шелк 20%',
            'colorName' => 'тускло-сиреневый; фиолетовый',
            'countryOfOrigin' => 'Россия',
            'careText' => 'гладить на низкой температуре',
        ], $result);
    }

    public function testFetchCardReturnsNullWhenNoShardResponds(): void
    {
        $adapter = new WildberriesAdapter(new MockHttpClient(array_fill(0, 30, new MockResponse('', ['http_code' => 404]))), 'test-agent');

        self::assertNull($adapter->fetchCard(self::URL));
    }

    public function testFetchCardReturnsNullWhenNoneOfFourFieldsMapped(): void
    {
        $responses = array_fill(0, 29, new MockResponse('', ['http_code' => 404]));
        array_unshift($responses, new MockResponse('{"options":[{"name":"Покрой","value":"свободный"}]}', ['http_code' => 200]));
        $adapter = new WildberriesAdapter(new MockHttpClient($responses), 'test-agent');

        self::assertNull($adapter->fetchCard(self::URL));
    }

    public function testFetchCardReturnsNullForNonWildberriesUrl(): void
    {
        $adapter = new WildberriesAdapter(new MockHttpClient([]), 'test-agent');

        self::assertNull($adapter->fetchCard('https://example.com/product/1'));
    }
}
