<?php

namespace App\Service;

use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * Хранилище векторов — Qdrant по REST (:6333). Одна коллышка `brand_chunks`,
 * Cosine, payload-index на brand_id (иначе фильтрованный поиск/удаление = full scan).
 * ID точек — детерминированный UUIDv5 от (brand_id, content_hash, chunk_index):
 * повторный эмбеддинг того же чанка перезаписывает, а не плодит дубли.
 */
class VectorStoreService
{
    private const VECTOR_SIZE = 1024;                       // qwen3-embedding:0.6b (bge-m3 даёт NaN в ollama 0.22)
    // Фиксированный namespace для UUIDv5 (любой стабильный UUID).
    private const ID_NAMESPACE = '6f9b1f7e-2c3d-5a4b-8e1f-0a1b2c3d4e5f';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $qdrantUrl,
        private readonly string $qdrantApiKey,
        private readonly string $collection,
    ) {
    }

    public static function pointId(int $brandId, string $contentHash, int $chunkIndex): string
    {
        return (string) Uuid::v5(
            Uuid::fromString(self::ID_NAMESPACE),
            "{$brandId}:{$contentHash}:{$chunkIndex}",
        );
    }

    /** Создаёт коллекцию + payload-index, если нет. Падает при несовпадении размерности. */
    public function ensureCollection(): void
    {
        $get = $this->request('GET', "/collections/{$this->collection}");
        if ($get['status'] === 200) {
            $size = $get['body']['result']['config']['params']['vectors']['size'] ?? null;
            if ($size !== null && (int) $size !== self::VECTOR_SIZE) {
                throw new \RuntimeException(
                    "Qdrant collection '{$this->collection}' has vector size {$size}, expected " . self::VECTOR_SIZE
                );
            }
            return;
        }

        $this->request('PUT', "/collections/{$this->collection}", [
            'vectors' => ['size' => self::VECTOR_SIZE, 'distance' => 'Cosine'],
        ]);
        $this->request('PUT', "/collections/{$this->collection}/index?wait=true", [
            'field_name'   => 'brand_id',
            'field_schema' => 'integer',
        ]);
    }

    /**
     * @param array<int,array{id:string,vector:float[],payload:array}> $points
     */
    public function upsertPoints(array $points): void
    {
        if ($points === []) {
            return;
        }
        $this->request('PUT', "/collections/{$this->collection}/points?wait=true", ['points' => $points]);
    }

    /**
     * @param float[] $queryVector
     * @return array<int,array{score:float,payload:array}>
     */
    public function searchByBrand(int $brandId, array $queryVector, int $topK = 6): array
    {
        $res = $this->request('POST', "/collections/{$this->collection}/points/search", [
            'vector'       => $queryVector,
            'limit'        => $topK,
            'with_payload' => true,
            'filter'       => $this->brandFilter($brandId),
        ]);

        return $res['body']['result'] ?? [];
    }

    /**
     * Глобальный семантический поиск по всей коллекции — без фильтра по бренду.
     * Для рекомендаций (app:brand:ask): один эмбеддинг запроса → топ-K чанков
     * разных брендов. Payload каждого хита содержит brand_id для группировки.
     *
     * @param float[] $queryVector
     * @return array<int,array{score:float,payload:array}>
     */
    public function search(array $queryVector, int $topK = 50): array
    {
        $res = $this->request('POST', "/collections/{$this->collection}/points/search", [
            'vector'       => $queryVector,
            'limit'        => $topK,
            'with_payload' => true,
        ]);

        return $res['body']['result'] ?? [];
    }

    public function deleteByBrand(int $brandId): void
    {
        $this->request('POST', "/collections/{$this->collection}/points/delete?wait=true", [
            'filter' => $this->brandFilter($brandId),
        ]);
    }

    /** Удалить чанки одного документа (soft-delete источника в админке). brand_id индексирован — фильтр быстрый. */
    public function deleteByDoc(int $brandId, int $docId): void
    {
        $this->request('POST', "/collections/{$this->collection}/points/delete?wait=true", [
            'filter' => ['must' => [
                ['key' => 'brand_id', 'match' => ['value' => $brandId]],
                ['key' => 'doc_id',   'match' => ['value' => $docId]],
            ]],
        ]);
    }

    /**
     * Векторы чанков бренда (для среднего вектора в графе перелинковки).
     * Один scroll без пагинации — limit покрывает типичный корпус бренда.
     *
     * @return array<int,float[]>
     */
    public function brandVectors(int $brandId, int $limit = 64): array
    {
        $res = $this->request('POST', "/collections/{$this->collection}/points/scroll", [
            'filter'       => $this->brandFilter($brandId),
            'limit'        => $limit,
            'with_payload' => false,
            'with_vector'  => true,
        ]);

        $vectors = [];
        foreach ($res['body']['result']['points'] ?? [] as $point) {
            if (isset($point['vector']) && is_array($point['vector'])) {
                $vectors[] = $point['vector'];
            }
        }

        return $vectors;
    }

    public function countByBrand(int $brandId): int
    {
        $res = $this->request('POST', "/collections/{$this->collection}/points/count", [
            'filter' => $this->brandFilter($brandId),
        ]);

        return (int) ($res['body']['result']['count'] ?? 0);
    }

    private function brandFilter(int $brandId): array
    {
        return ['must' => [['key' => 'brand_id', 'match' => ['value' => $brandId]]]];
    }

    /**
     * @return array{status:int,body:array}
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($this->qdrantApiKey !== '') {
            $headers['api-key'] = $this->qdrantApiKey;
        }

        $options = ['headers' => $headers, 'timeout' => 30];
        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            $response = $this->httpClient->request($method, rtrim($this->qdrantUrl, '/') . $path, $options);
            $status   = $response->getStatusCode();
            // 404 на GET коллекции — валидный ответ (её ещё нет), не ошибка.
            if ($status >= 400 && !($status === 404 && $method === 'GET')) {
                throw new \RuntimeException("Qdrant {$method} {$path} -> {$status}: " . $response->getContent(false));
            }
            $body = $status === 404 ? [] : $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw new \RuntimeException("Qdrant request failed ({$method} {$path}): " . $e->getMessage(), 0, $e);
        }

        return ['status' => $status, 'body' => $body];
    }
}
