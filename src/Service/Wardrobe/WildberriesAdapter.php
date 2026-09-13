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

    /**
     * Полная карточка (характеристики: состав/цвет/страна/уход) — отдельный JSON на том
     * же CDN, что и картинка у fetch() (v4/detail характеристик не отдаёт). Приоритетный
     * источник атрибутов для app:wardrobe:prepare-prod-items: структурные данные от
     * производителя достовернее и фото-распознавания, и разбора названия.
     *
     * WB отдаёт валидный в остальном JSON с СЫРЫМИ (неэкранированными) переводами строк
     * внутри строковых значений — стандартный json_decode падает ("Control character
     * error"), поэтому байты 0x00–0x1F (единственные запрещённые внутри JSON-строк)
     * заменяются на пробел до декодирования; на структуру JSON это не влияет — пробельные
     * символы между токенами и так незначимы.
     *
     * @return array{materialText:?string,colorName:?string,countryOfOrigin:?string,careText:?string}|null
     *         null — карточка недоступна или не отдала НИ ОДНОГО из четырёх полей;
     *         вызывающий в этом случае падает на фото/название.
     */
    public function fetchCard(string $url): ?array
    {
        $nm = $this->extractNmId($url);
        if ($nm === null) {
            return null;
        }

        $data = $this->fetchCardJson($nm);
        if ($data === null) {
            return null;
        }

        $options = $this->collectOptions($data);
        $mapped = [
            'materialText'    => $options['Состав'] ?? null,
            'colorName'       => $options['Цвет'] ?? null,
            'countryOfOrigin' => $options['Страна производства'] ?? null,
            'careText'        => $options['Уход за вещами'] ?? null,
        ];

        return $mapped === array_fill_keys(array_keys($mapped), null) ? null : $mapped;
    }

    /** @return array<string,mixed>|null декодированный card.json первого ответившего шарда */
    private function fetchCardJson(int $nm): ?array
    {
        $vol  = intdiv($nm, 100000);
        $part = intdiv($nm, 1000);

        $responses = [];
        for ($i = 1; $i <= self::BASKET_HOSTS_MAX; $i++) {
            $url = sprintf('https://basket-%02d.wbbasket.ru/vol%d/part%d/%d/info/ru/card.json', $i, $vol, $part, $nm);
            try {
                $responses[$url] = $this->httpClient->request('GET', $url, [
                    'headers' => ['User-Agent' => $this->userAgent],
                    'timeout' => 5,
                ]);
            } catch (\Throwable) {
                continue;
            }
        }

        // Тот же приём, что у findImageUrl(): дочитываем ВСЕ ответы до конца — иначе
        // непрочитанные 404 у неверных шардов кидают исключение из своего __destruct()
        // уже после выхода из метода (Symfony HttpClient считает статус непрочитанным).
        $body = null;
        foreach ($responses as $response) {
            try {
                $code = $response->getStatusCode();
                if ($body === null && $code === 200) {
                    $body = $response->getContent(false);
                }
            } catch (\Throwable) {
                continue;
            }
        }

        if ($body === null) {
            return null;
        }

        $decoded = json_decode(preg_replace('/[\x00-\x1F]/', ' ', $body) ?? $body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<string,string> имя характеристики → значение; options[] + grouped_options[].options[], дедуп по имени (первое встреченное значение). */
    private function collectOptions(array $data): array
    {
        $out = [];
        foreach ((is_array($data['options'] ?? null) ? $data['options'] : []) as $option) {
            $this->addOption($out, $option);
        }
        foreach ((is_array($data['grouped_options'] ?? null) ? $data['grouped_options'] : []) as $group) {
            foreach ((is_array($group['options'] ?? null) ? $group['options'] : []) as $option) {
                $this->addOption($out, $option);
            }
        }

        return $out;
    }

    /** @param array<string,string> $out */
    private function addOption(array &$out, mixed $option): void
    {
        if (!is_array($option)) {
            return;
        }
        $name = trim((string) ($option['name'] ?? ''));
        $value = trim((string) ($option['value'] ?? ''));
        if ($name === '' || $value === '' || isset($out[$name])) {
            return;
        }
        $out[$name] = mb_substr($value, 0, 2000);
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
