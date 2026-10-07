<?php

declare(strict_types=1);

namespace App\Service\Social;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Комментарии IG и Private Reply (Instagram API with Instagram Login, graph.instagram.com).
 * Токен идёт заголовком Authorization, а не в query — чтобы он не попал в URL исключений и логов.
 *
 * Private Reply: POST /{ig-user-id}/messages {recipient:{comment_id}, message:{text}};
 * окно — 7 дней от комментария, один ответ на комментарий, текст ≤1000 байт UTF-8.
 */
class InstagramComments
{
    private const API_BASE = 'https://graph.instagram.com/v22.0';
    private const PAGE_SIZE = 50;
    public const MAX_TEXT_BYTES = 1000;

    /** Коды Graph API, при которых повтор имеет смысл (лимиты, временный сбой). */
    private const TRANSIENT_CODES = [1, 2, 4, 17, 32, 341, 613];

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function ownUsername(string $token): string
    {
        $data = $this->request('GET', '/me', $token, ['query' => ['fields' => 'username']]);

        return (string) ($data['username'] ?? '');
    }

    /**
     * Комментарии верхнего уровня к медиа, новые первыми; пагинация идёт, пока не встретится
     * комментарий старше $since (или не кончатся страницы / не достигнут $maxPages).
     *
     * @return list<array{id: string, text: string, username: string, timestamp: \DateTimeImmutable}>
     */
    public function comments(string $mediaId, string $token, \DateTimeInterface $since, int $maxPages = 10): array
    {
        $result = [];
        $options = ['query' => ['fields' => 'id,text,username,timestamp', 'limit' => self::PAGE_SIZE]];
        $path = '/' . rawurlencode($mediaId) . '/comments';
        for ($page = 0; $page < $maxPages; $page++) {
            $data = $this->request('GET', $path, $token, $options);
            foreach ($data['data'] ?? [] as $row) {
                $at = new \DateTimeImmutable((string) ($row['timestamp'] ?? 'now'));
                if ($at < $since) {
                    return $result;
                }
                $result[] = ['id' => (string) $row['id'], 'text' => (string) ($row['text'] ?? ''), 'username' => (string) ($row['username'] ?? ''), 'timestamp' => $at];
            }
            $after = $data['paging']['cursors']['after'] ?? null;
            if (!isset($data['paging']['next']) || !is_string($after) || $after === '') {
                break;
            }
            $options['query']['after'] = $after;
        }

        return $result;
    }

    /** @return string id отправленного сообщения */
    public function sendPrivateReply(string $igUserId, string $commentId, string $text, string $token): string
    {
        if (strlen($text) > self::MAX_TEXT_BYTES) {
            throw new InstagramApiException(sprintf('Текст ответа %d байт > %d', strlen($text), self::MAX_TEXT_BYTES), true);
        }
        $data = $this->request('POST', '/' . rawurlencode($igUserId) . '/messages', $token, ['json' => [
            'recipient' => ['comment_id' => $commentId],
            'message' => ['text' => $text],
        ]]);

        return (string) ($data['message_id'] ?? '');
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, string $token, array $options): array
    {
        $options['headers'] = ['Authorization' => 'Bearer ' . $token];
        $options['timeout'] = 30;
        try {
            $response = $this->httpClient->request($method, self::API_BASE . $path, $options);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new InstagramApiException('IG: сеть/разбор ответа: ' . $e::class, false);
        }

        if (isset($data['error']) || $status >= 400) {
            $code = (int) ($data['error']['code'] ?? 0);
            $message = (string) ($data['error']['message'] ?? 'unknown');
            $transient = $status >= 500 || $status === 429 || in_array($code, self::TRANSIENT_CODES, true);
            throw new InstagramApiException(sprintf('IG %s %s: %s (HTTP %d, code %d)', $method, preg_replace('~/\d+~', '/{id}', $path), $message, $status, $code), !$transient);
        }

        return $data;
    }
}
