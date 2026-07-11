<?php

namespace App\Service\Wardrobe;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Прямое обращение к публичному API Wildberries (без LLM): название, бренд, цена,
 * размеры в наличии, фото. Fail-soft на каждом шаге — любая ошибка/неожиданный
 * формат → null, вызывающий (WardrobeAiService) падает на scraper+LLM путь.
 *
 * Формат проверен вручную 2026-07-11 (nm=383000039 — карточка с реальным остатком):
 * GET https://card.wb.ru/cards/v4/detail?appType=1&curr=rub&dest=-1257786&spp=30&nm=<nm>
 * → products[0]: name, brand, sizes[].name (+origName), sizes[].stocks[] (пусто = нет
 * в наличии), sizes[].price.product (копейки, ИТОГОВАЯ/скидочная цена).
 * Старый card.wb.ru/cards/v2/detail отдаёт anti-bot PoW-challenge (404 + x-pow) — не
 * использовать.
 *
 * Картинка — CDN-шард basket-NN.wbbasket.ru, номер шарда непостоянен во времени
 * (WB периодически перекладывает vol-диапазоны) → перебор 01..N без надёжного
 * заранее известного номера; best-effort, null не блокер.
 */
class WildberriesAdapter
{
    private const DETAIL_URL = 'https://card.wb.ru/cards/v4/detail';
    private const BASKET_HOSTS_MAX = 30;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $userAgent = 'Mozilla/5.0 (compatible; WearbaseBot/1.0)',
    ) {
    }

    /**
     * @return array{name:string,sizes:?string,price:?int,imageUrl:?string}|null
     *         null — не удалось получить данные, вызывающий падает на scraper+LLM
     */
    public function fetch(string $url): ?array
    {
        $nm = $this->extractNmId($url);
        if ($nm === null) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', self::DETAIL_URL, [
                'query' => [
                    'appType' => 1,
                    'curr'    => 'rub',
                    'dest'    => -1257786,
                    'spp'     => 30,
                    'nm'      => $nm,
                ],
                'headers' => ['User-Agent' => $this->userAgent],
                'timeout' => 8,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        $product = $data['products'][0] ?? null;
        if (!is_array($product)) {
            return null;
        }

        $name = trim(trim((string) ($product['brand'] ?? '')) . ' ' . trim((string) ($product['name'] ?? '')));
        if ($name === '') {
            return null;
        }

        [$sizes, $price] = $this->extractSizesAndPrice(is_array($product['sizes'] ?? null) ? $product['sizes'] : []);

        return [
            'name'     => mb_substr($name, 0, 255),
            'sizes'    => $sizes,
            'price'    => $price,
            'imageUrl' => $this->findImageUrl($nm),
        ];
    }

    /** @return array{0: ?string, 1: ?int} */
    private function extractSizesAndPrice(array $sizes): array
    {
        $labels = [];
        $price = null;

        foreach ($sizes as $size) {
            if (!is_array($size) || ($size['stocks'] ?? []) === []) {
                continue; // нет в наличии
            }
            $label = trim((string) ($size['name'] ?: ($size['origName'] ?? '')));
            if ($label !== '') {
                $labels[] = $label;
            }
            $kopecks = $size['price']['product'] ?? null;
            if ($price === null && is_numeric($kopecks)) {
                $price = (int) round(((float) $kopecks) / 100);
            }
        }

        return [$labels !== [] ? implode(', ', array_unique($labels)) : null, $price];
    }

    /** nm-id из URL вида wildberries.ru/catalog/<nm>/detail.aspx (и вариаций с query/якорем). */
    private function extractNmId(string $url): ?int
    {
        if (!str_contains(strtolower($url), 'wildberries.ru')) {
            return null;
        }
        if (preg_match('~/catalog/(\d+)~', $url, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Перебор CDN-шардов (конкурентно — Symfony HttpClient не блокирует до
     * обращения к ответу, запросы идут параллельно через curl_multi).
     */
    private function findImageUrl(int $nm): ?string
    {
        $vol  = intdiv($nm, 100000);
        $part = intdiv($nm, 1000);

        $responses = [];
        for ($i = 1; $i <= self::BASKET_HOSTS_MAX; $i++) {
            $imageUrl = sprintf('https://basket-%02d.wbbasket.ru/vol%d/part%d/%d/images/big/1.webp', $i, $vol, $part, $nm);
            try {
                $responses[$imageUrl] = $this->httpClient->request('HEAD', $imageUrl, [
                    'headers' => ['User-Agent' => $this->userAgent],
                    'timeout' => 5,
                ]);
            } catch (\Throwable) {
                continue;
            }
        }

        // ВАЖНО: обходим ВСЕ ответы до конца (не return при первом найденном) — иначе
        // ещё не «прочитанные» HEAD-ответы (404 у неверных шардов) кидают исключение
        // из своего __destruct() уже ПОСЛЕ выхода из функции (Symfony HttpClient
        // считает непрочитанный статус ошибкой), и это исключение ничем не поймать.
        $found = null;
        foreach ($responses as $imageUrl => $response) {
            try {
                // ВСЕГДА вызываем getStatusCode() (не short-circuit по $found) — иначе
                // ответы после найденного совпадения останутся «непотреблёнными» и
                // всё равно кинут исключение из __destruct().
                $code = $response->getStatusCode();
                if ($found === null && $code === 200) {
                    $found = $imageUrl;
                }
            } catch (\Throwable) {
                // 404 у неверного шарда — ожидаемо, продолжаем перебор
            }
        }

        return $found;
    }
}
