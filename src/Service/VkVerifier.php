<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Верификация владельца бренда через VK ID (OAuth 2.1 + PKCE):
 * пользователь должен быть администратором официальной группы бренда.
 *
 * ВНИМАНИЕ: реализация по VK ID flow, но НЕ проверена вживую — требует
 * зарегистрированного приложения (VK_APP_ID/VK_APP_SECRET) и redirect_uri.
 * Точки, которые нужно сверить с боевым VK-приложением:
 *   - точное имя scope для управления группами (здесь 'groups');
 *   - параметр device_id приходит на redirect вместе с code — обязателен в обмене;
 *   - сигнатура groups.getById (param group_id) и поля ответа.
 *
 * @see https://id.vk.com/about/business/go/docs (VK ID, OAuth 2.1)
 */
class VkVerifier
{
    private const AUTHORIZE_URL = 'https://id.vk.com/authorize';
    private const TOKEN_URL     = 'https://id.vk.com/oauth2/auth';
    private const API_URL       = 'https://api.vk.com/method';
    private const API_VERSION   = '5.199';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $appId = '',
        private readonly string $appSecret = '',
    ) {}

    public function isConfigured(): bool
    {
        return $this->appId !== '' && $this->appSecret !== '';
    }

    /** code_verifier для PKCE (хранить в сессии до callback) */
    public function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    public function buildAuthorizeUrl(string $redirectUri, string $state, string $codeVerifier): string
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return self::AUTHORIZE_URL . '?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => $this->appId,
            'redirect_uri'          => $redirectUri,
            'scope'                 => 'groups',
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Обмен code на access_token. $deviceId приходит на redirect вместе с code.
     * @return string|null access_token или null при ошибке
     */
    public function exchangeCode(string $code, string $deviceId, string $codeVerifier, string $redirectUri): ?string
    {
        try {
            $resp = $this->http->request('POST', self::TOKEN_URL, [
                'body' => [
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'code_verifier' => $codeVerifier,
                    'client_id'     => $this->appId,
                    'device_id'     => $deviceId,
                    'redirect_uri'  => $redirectUri,
                ],
            ]);
            $data = $resp->toArray(false);

            return $data['access_token'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Является ли владелец токена админом группы, заданной screen-name/id из ссылки бренда.
     */
    public function isAdminOfGroup(string $accessToken, string $brandGroupRef): bool
    {
        $groupId = $this->resolveGroupId($accessToken, $brandGroupRef);
        if ($groupId === null) {
            return false;
        }

        try {
            $resp = $this->http->request('GET', self::API_URL . '/groups.get', [
                'query' => [
                    'filter'       => 'admin',
                    'extended'     => 0,
                    'access_token' => $accessToken,
                    'v'            => self::API_VERSION,
                ],
            ]);
            $items = $resp->toArray(false)['response']['items'] ?? [];

            return in_array($groupId, array_map('intval', $items), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Резолвит числовой id группы по screen-name из ссылки бренда (vk.com/<ref>). */
    private function resolveGroupId(string $accessToken, string $ref): ?int
    {
        // Чистая числовая ссылка вида club12345 / public12345
        if (preg_match('~^(?:club|public)?(\d+)$~', $ref, $m)) {
            return (int) $m[1];
        }

        try {
            $resp = $this->http->request('GET', self::API_URL . '/groups.getById', [
                'query' => [
                    'group_id'     => $ref,
                    'access_token' => $accessToken,
                    'v'            => self::API_VERSION,
                ],
            ]);
            $data = $resp->toArray(false);
            // VK возвращает либо response.groups[0].id, либо response[0].id (в зависимости от версии)
            $group = $data['response']['groups'][0] ?? ($data['response'][0] ?? null);

            return isset($group['id']) ? (int) $group['id'] : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
