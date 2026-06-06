<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WildberriesClient
{
    // С .43 (где крутится команда) туннель виден как docker-gateway 172.17.0.1.
    // С Mac задай WB_PROXY=socks5://192.168.2.43:1080 в .env.local.
    private const DEFAULT_PROXY = 'socks5://172.17.0.1:1080';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125 Safari/537.36';
    private const SEARCH_URL = 'https://search.wb.ru/exactmatch/ru/common/v5/search';
    private const BRAND_CATALOG_URL = 'https://catalog.wb.ru/brands/v2/catalog';
    private const PRESET_CATALOG_URL = 'https://catalog.wb.ru/catalog/presets/v4/catalog';
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY = 30;
    private const REQUEST_PAUSE = 2_000_000;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire('%env(default::WB_PROXY)%')]
        private ?string $proxy = null,
    ) {}

    /**
     * Ищет товары бренда на WB, возвращает только те, что совпадают по brand-имени.
     *
     * @return array<array{id: int, name: string, brand: string, subj_name?: string}>
     *
     * @throws \RuntimeException если поисковый запрос к WB не удался (3 ретрая)
     */
    public function searchBrandProducts(string $brandName): array
    {
        $encoded = urlencode($brandName);
        $url = self::SEARCH_URL . "?appType=1&curr=rub&dest=-1257786&query={$encoded}&resultset=catalog&sort=popular&spp=30";

        $data = $this->request($url);

        $products = $data['data']['products'] ?? [];

        if (empty($products)) {
            $products = $this->tryBrandCatalogFallback($data);
        }

        if (empty($products)) {
            $this->logger->debug('WB empty response for brand', [
                'brand' => $brandName,
                'url' => $url,
                'response' => $data,
            ]);
            return [];
        }

        return $this->matchByBrand($products, $brandName);
    }

    private function tryBrandCatalogFallback(array $searchData): array
    {
        $metadata = $searchData['metadata'] ?? [];
        $context = $metadata['context'] ?? [];
        $catalogValue = $metadata['catalog_value'] ?? '';

        $url = null;
        $label = '';

        // preset != brandId. Для точного бренда WB отдаёт preset → товары берём из
        // presets-каталога по preset (НЕ brands/v2/catalog?brand=preset — это давало 404).
        if (in_array('brand', $context, true) && preg_match('/preset=(\d+)/', $catalogValue, $m)) {
            $url = self::PRESET_CATALOG_URL . "?appType=1&curr=rub&dest=-1257786&preset={$m[1]}&sort=popular&spp=30";
            $label = "preset={$m[1]}";
        } elseif (!empty($metadata['brandId'])) {
            $bid = (int) $metadata['brandId'];
            $url = self::BRAND_CATALOG_URL . "?appType=1&curr=rub&dest=-1257786&brand={$bid}&sort=popular&spp=30";
            $label = "brandId={$bid}";
        }

        if ($url === null) {
            return [];
        }

        try {
            $data = $this->request($url);

            return $data['data']['products'] ?? [];
        } catch (\RuntimeException $e) {
            $this->logger->warning("WB catalog fallback failed ({$label}): {$e->getMessage()}");

            return [];
        }
    }

    /**
     * @return array<array{id: int, name: string, brand: string, subj_name?: string}>
     */
    private function matchByBrand(array $products, string $brandName): array
    {
        $normalizedNeedle = mb_strtolower(trim($brandName));
        $matched = [];

        foreach ($products as $p) {
            $productBrand = mb_strtolower(trim($p['brand'] ?? ''));
            if ($productBrand === $normalizedNeedle) {
                $matched[] = [
                    'id' => (int) ($p['id'] ?? $p['nm'] ?? 0),
                    'name' => $p['name'] ?? '',
                    'brand' => $p['brand'] ?? '',
                    'subj_name' => $p['subj_name'] ?? $p['subjName'] ?? '',
                ];
            }
        }

        return $matched;
    }

    /**
     * GET-запрос к WB через SOCKS5-прокси с backoff на 429.
     *
     * @throws \RuntimeException после MAX_RETRIES неудачных попыток
     */
    private function request(string $url): array
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 1) {
                sleep(self::RETRY_DELAY);
            }

            try {
                usleep(self::REQUEST_PAUSE);

                $response = $this->httpClient->request('GET', $url, [
                    'proxy' => $this->proxy ?: self::DEFAULT_PROXY,
                    'timeout' => 25,
                    'headers' => [
                        'User-Agent' => self::USER_AGENT,
                    ],
                ]);

                $statusCode = $response->getStatusCode();

                if ($statusCode === 429) {
                    $this->logger->warning("WB 429 на попытке {$attempt}: {$url}");
                    $lastError = '429';
                    continue;
                }

                if ($statusCode !== 200) {
                    // не-429 4xx/5xx ретраями не лечится → fail-fast, не жжём прокси/IP
                    $this->logger->warning("WB HTTP {$statusCode}: {$url}");
                    throw new \RuntimeException("WB HTTP {$statusCode}");
                }

                return $response->toArray();
            } catch (\Exception $e) {
                $this->logger->error("WB request error (attempt {$attempt}): {$e->getMessage()}");
                $lastError = $e->getMessage();
            }
        }

        $this->logger->error("WB request failed after " . self::MAX_RETRIES . " attempts: {$url} ({$lastError})");
        throw new \RuntimeException("Wildberries request failed after " . self::MAX_RETRIES . " attempts: {$lastError}");
    }
}
