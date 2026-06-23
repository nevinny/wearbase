<?php

namespace App\Service\Agent;

use App\Entity\Brand;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Скрытие бренда с публикации: soft-hide локально (`status=Disabled`, политика soft-delete)
 * + снятие с прод-каталога через агент-API `/api/v1/brands/unpublish` (X-Agent-Token + HMAC).
 * Fail-soft: прод недоступен/не настроен → локальный hide всё равно применён.
 *
 * Общий путь для: TG-кнопки «Скрыть с публикации», admin /admin/rag/review hide,
 * revalidate-content. (HMAC-вызов исторически дублируется в контроллере/командах — здесь
 * единая точка для нового callback-флоу бота.)
 */
class BrandUnpublisher
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::PROD_API_URL)%')]
        private readonly ?string $prodApiUrl,
        #[Autowire('%env(default::AGENT_API_TOKEN)%')]
        private readonly ?string $agentToken,
        #[Autowire('%env(default::AGENT_API_SECRET)%')]
        private readonly ?string $agentSecret,
    ) {
    }

    /**
     * @return array{ok:bool, title:string, message:string} ok=удалось ли (хотя бы локально)
     */
    public function hide(int $brandId): array
    {
        $brand = $this->em->find(Brand::class, $brandId);
        if ($brand === null) {
            return ['ok' => false, 'title' => "#{$brandId}", 'message' => "бренд #{$brandId} не найден"];
        }
        $title = $brand->getTitle() ?? "#{$brandId}";
        $slug  = (string) $brand->getSlug();

        // 1) Локальный soft-hide (никогда не физический DELETE — политика проекта).
        // «Скрыт» = Disabled (доменный переход brand->unpublish(), как в agent-API unpublish).
        $brand->unpublish();
        $this->em->flush();

        // 2) Снять с прод-каталога.
        $prod = $this->unpublishOnProd($slug, $brandId);

        return ['ok' => true, 'title' => $title, 'message' => $prod];
    }

    private function unpublishOnProd(string $slug, int $id): string
    {
        if (trim((string) $this->prodApiUrl) === '' || trim((string) $this->agentToken) === '' || trim((string) $this->agentSecret) === '') {
            return 'локально скрыт (прод-API не настроен)';
        }
        if ($slug === '') {
            return 'локально скрыт (нет slug — на проде не снять)';
        }
        try {
            $body = json_encode(['slug' => $slug], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $data = $this->httpClient->request('POST', rtrim((string) $this->prodApiUrl, '/') . '/api/v1/brands/unpublish', [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'X-Agent-Token' => (string) $this->agentToken,
                    'X-Signature'   => hash_hmac('sha256', $body, (string) $this->agentSecret),
                ],
                'body'    => $body,
                'timeout' => 8,
            ])->toArray(false);

            return match ($data['status'] ?? null) {
                'unpublished' => 'снят с прод-каталога',
                'not_found'   => 'локально скрыт (на проде не было)',
                default       => 'локально скрыт (неожиданный ответ прода)',
            };
        } catch (\Throwable $e) {
            return "локально скрыт (прод недоступен: {$e->getMessage()})";
        }
    }
}
