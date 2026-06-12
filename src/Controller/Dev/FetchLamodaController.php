<?php

declare(strict_types=1);

namespace App\Controller\Dev;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FetchLamodaController extends AbstractController
{
    #[Route('/dev/fetch-lamoda-brands', name: 'dev_fetch_lamoda_brands')]
    public function __invoke(HttpClientInterface $httpClient): JsonResponse
    {
        $response = $httpClient->request('GET', 'https://www.lamoda.ru/api/v1/brands/list', [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ]);

        $data = $response->toArray();

        $brands = array_map(fn($b) => [
            'title' => $b['title'],
            'slug' => $b['seo_tail'] ?? '',
            'is_premium' => $b['is_premium'] ?? false,
            'is_kids' => $b['is_kids'] ?? false,
            'is_beauty' => $b['is_beauty'] ?? false,
            'is_sport' => $b['is_sport'] ?? false,
            'source' => 'lamoda.ru',
        ], $data);

        $file = dirname(__DIR__, 3) . '/_sql/lamoda_brands_raw.json';
        file_put_contents($file, json_encode($brands, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return new JsonResponse([
            'status' => 'ok',
            'count' => count($brands),
            'file' => basename($file),
        ]);
    }
}
