<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Эмбеддинги через локальную ollama (нативный /api/embed, модель bge-m3, 1024-dim).
 * keep_alive держит и embed-, и chat-модель тёплыми, иначе ollama молотит load/unload
 * при чередовании эмбеддингов и генерации.
 */
class EmbeddingService
{
    private const KEEP_ALIVE = '30m';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $embedUrl,
        private readonly string $embedModel,
    ) {
    }

    /**
     * @param string[] $texts
     * @return float[][] по одному вектору на вход, порядок сохраняется
     */
    public function embedBatch(array $texts, int $timeout = 180): array
    {
        if ($texts === []) {
            return [];
        }

        try {
            $response = $this->httpClient->request('POST', $this->embedUrl, [
                'headers' => ['Content-Type' => 'application/json'],
                'json'    => [
                    'model'      => $this->embedModel,
                    'input'      => array_values($texts),
                    'keep_alive' => self::KEEP_ALIVE,
                ],
                'timeout' => $timeout,
            ]);

            $status = $response->getStatusCode();
            if ($status !== 200) {
                throw new \RuntimeException("Embedding HTTP {$status}: " . $response->getContent(false));
            }
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Embedding request failed: ' . $e->getMessage(), 0, $e);
        }

        $embeddings = $data['embeddings'] ?? null;
        if (!is_array($embeddings) || count($embeddings) !== count($texts)) {
            throw new \RuntimeException('Embedding response malformed (expected ' . count($texts) . ' vectors)');
        }

        return $embeddings;
    }

    /** @return float[] один 1024-мерный вектор */
    public function embed(string $text, int $timeout = 60): array
    {
        return $this->embedBatch([$text], $timeout)[0];
    }
}
