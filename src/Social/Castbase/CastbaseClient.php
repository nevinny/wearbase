<?php

declare(strict_types=1);

namespace App\Social\Castbase;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Клиент REST API castbase (/v1). Каждый POST несёт Idempotency-Key: повтор после сетевой ошибки
 * с тем же ключом и телом не создаёт дубль. Ошибки (application/problem+json) → CastbaseException.
 */
class CastbaseClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(CASTBASE_API_URL)%')]
        private readonly string $baseUrl,
        #[Autowire('%env(CASTBASE_API_KEY)%')]
        #[\SensitiveParameter]
        private readonly string $apiKey,
    ) {
    }

    /** POST /v1/media (multipart, поле file) → media_id. */
    public function uploadMedia(string $absPath, string $idempotencyKey): string
    {
        $form = new FormDataPart(['file' => DataPart::fromPath($absPath)]);
        $data = $this->request('POST', '/v1/media', [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body' => $form->bodyToIterable(),
            'timeout' => 300,
        ], $idempotencyKey);

        return (string) ($data['media_id'] ?? throw new CastbaseException('castbase: в ответе /v1/media нет media_id'));
    }

    /**
     * POST /v1/posts. Ответ — пост (id, status, targets).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createPost(array $body, string $idempotencyKey): array
    {
        return $this->request('POST', '/v1/posts', ['json' => $body], $idempotencyKey);
    }

    /**
     * POST /v1/channels: подключить IG-аккаунт токеном (BYO). Токен уходит только в тело запроса.
     * Ответ 202 — канал в статусе pending; итог — getChannel().
     *
     * @return array<string, mixed>
     */
    public function connectInstagram(#[\SensitiveParameter] string $accessToken, string $label, string $idempotencyKey): array
    {
        return $this->request('POST', '/v1/channels', ['json' => [
            'platform' => 'instagram',
            'access_token' => $accessToken,
            'label' => $label,
        ]], $idempotencyKey);
    }

    /**
     * GET /v1/channels/{id}: канал и его статус (pending → active | failed | reauth_required; disabled).
     * POST /v1/channels отвечает 202 pending — итог проверки токена приходит позже, его читают здесь.
     *
     * @return array<string, mixed>
     */
    public function getChannel(string $channelId): array
    {
        return $this->request('GET', '/v1/channels/' . rawurlencode($channelId));
    }

    /**
     * GET /v1/events?after=&limit= → ['data' => [...], 'next_after' => int].
     *
     * @return array<string, mixed>
     */
    public function events(int $after, int $limit = 100): array
    {
        return $this->request('GET', '/v1/events?after=' . $after . '&limit=' . $limit);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options = [], ?string $idempotencyKey = null): array
    {
        $headers = ($options['headers'] ?? []) + [
            'Authorization' => ['Bearer ' . $this->apiKey],
            'Accept' => ['application/json'],
        ];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = [$idempotencyKey];
        }
        $options['headers'] = $headers;
        $options['timeout'] ??= 60;

        try {
            $response = $this->http->request($method, rtrim($this->baseUrl, '/') . $path, $options);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            // Токен/ключ в сообщение не попадают: только текст транспортной ошибки.
            throw new CastbaseException('castbase: сетевая ошибка: ' . $e->getMessage(), 0, null, true);
        } catch (\JsonException|\Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface) {
            $data = [];
        }

        if ($status >= 400) {
            $detail = (string) ($data['detail'] ?? '');
            $fieldErrors = [];
            foreach ((array) ($data['errors'] ?? []) as $error) {
                if (is_array($error)) {
                    $fieldErrors[] = ['field' => (string) ($error['field'] ?? ''), 'code' => (string) ($error['code'] ?? ''), 'message' => (string) ($error['message'] ?? '')];
                }
            }
            // Коды полей — в сообщение: по нему (last_error, алерт) оператор видит причину, а не только validation_failed.
            $fields = implode(', ', array_map(static fn (array $e) => $e['field'] . ':' . $e['code'], $fieldErrors));
            throw new CastbaseException(
                sprintf('castbase %s %s -> %d %s: %s%s', $method, explode('?', $path)[0], $status, (string) ($data['code'] ?? ''), $detail, $fields !== '' ? ' [' . $fields . ']' : ''),
                $status,
                isset($data['code']) ? (string) $data['code'] : null,
                $status >= 500 || $status === 429,
                $fieldErrors,
            );
        }

        return $data;
    }
}
