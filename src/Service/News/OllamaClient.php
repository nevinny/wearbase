<?php

declare(strict_types=1);

namespace App\Service\News;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Ollama HTTP-клиент (/api/chat). URL и модель из env:
 * OLLAMA_URL (по умолчанию http://127.0.0.1:11434), OLLAMA_MODEL
 * (по умолчанию qwen3.5:27b — проверенная текстовая модель этого стека,
 * см. BenchModelsCommand).
 */
final class OllamaClient implements NewsLlmClientInterface
{
    private string $baseUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        ?string $ollamaUrl = null,
        private readonly string $model = 'qwen3.5:27b',
        private readonly float $timeoutSeconds = 120.0,
    ) {
        $this->baseUrl = rtrim($ollamaUrl ?: self::DEFAULT_URL, '/');
    }

    public function chat(array $messages, ?string $format = null): string
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => false,
            'options' => ['temperature' => 0.4],
        ];
        if ($format !== null) {
            $payload['format'] = $format;
        }

        try {
            $resp = $this->httpClient->request('POST', $this->baseUrl . '/api/chat', [
                'json' => $payload,
                'timeout' => $this->timeoutSeconds,
            ]);
            $data = json_decode($resp->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface |
                 \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface |
                 \JsonException $e
        ) {
            throw new NewsLlmUnavailableException('ollama недоступна: ' . $e->getMessage(), previous: $e);
        }

        return trim((string) ($data['message']['content'] ?? ''));
    }
}
