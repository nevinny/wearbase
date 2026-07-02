<?php

namespace App\Service\Agent;

use App\Entity\Brand;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Доставка бренда на прод через agent-API /api/v1/brands/upsert (X-Agent-Token + HMAC тела).
 * Единая точка для всех Mac-side потребителей (extract --push, точечные бэкафиллы и т.п.) —
 * раньше каждый вызов собирал HTTP-запрос сам, логика дублировалась.
 *
 * Успешный upsert инкрементирует agent_sync_version на dev (flush внутри) — следующий пуш
 * поедет v+1. Трекинг pushed_at/push_error пайплайна остаётся на вызывающем (нужен не всем).
 */
class ProdBrandPusher
{
    private const TIMEOUT_SEC = 60;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly BrandPayloadAssembler $assembler,
        private readonly EntityManagerInterface $em,
        private readonly ?string $prodApiUrl,
        private readonly ?string $apiToken,
        private readonly ?string $apiSecret,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->prodApiUrl) !== ''
            && trim((string) $this->apiToken) !== ''
            && trim((string) $this->apiSecret) !== '';
    }

    /**
     * Собирает payload и доставляет бренд на прод. Бросает исключение при любом сбое
     * (не настроен / HTTP / неожиданный ответ) — обработка на вызывающем.
     *
     * @return array{status:string, brand_id?:int|string} ответ прода (created|updated|skipped)
     */
    public function upsert(Brand $brand): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('agent-API не настроен (PROD_API_URL/AGENT_API_TOKEN/AGENT_API_SECRET)');
        }

        $payload = $this->assembler->assemble($brand);
        $body    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = $this->httpClient->request('POST', rtrim((string) $this->prodApiUrl, '/') . '/api/v1/brands/upsert', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'X-Agent-Token' => (string) $this->apiToken,
                'X-Signature'   => hash_hmac('sha256', $body, (string) $this->apiSecret),
            ],
            'body'    => $body,
            'timeout' => self::TIMEOUT_SEC,
        ]);

        $status = $response->getStatusCode();
        $data   = $status === 200 ? $response->toArray(false) : [];
        if ($status !== 200 || !in_array($data['status'] ?? '', ['created', 'updated', 'skipped'], true)) {
            throw new \RuntimeException(sprintf('HTTP %d: %s', $status, mb_substr($response->getContent(false), 0, 300)));
        }

        $brand->setAgentSyncVersion((int) $payload['agent_sync_version']);
        $this->em->flush();

        return $data;
    }
}
