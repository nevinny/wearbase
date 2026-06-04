<?php

namespace App\Controller\Api;

use App\Entity\Brand;
use App\Service\Agent\BrandIngestService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Агент-API (прод): приём готового контента брендов от локального агента-генератора
 * (app:brand:push). Прод НЕ видит LAN генератора — все данные (включая логотип
 * base64) едут в payload.
 *
 * Auth (паттерн платёжных вебхуков, firewall'ы не трогаем):
 *  - X-Agent-Token: <AGENT_API_TOKEN>                       (hash_equals; заголовок
 *    Authorization срезается nginx/Apache без спец-конфига — поэтому кастомный)
 *  - X-Signature: hex(hmac-sha256(body, AGENT_API_SECRET))  (подпись тела)
 * Rate limit: framework.rate_limiter.agent_api (429 при превышении).
 *
 * Формат POST /api/v1/brands/upsert (application/json):
 * {
 *   "slug": "...", "title": "...", "city": "...",
 *   "description": "...", "anons": "...",
 *   "meta": {"title": "...", "description": "...", "keywords": "..."},
 *   "contacts": {"email": "...", "phone": "...", "address": "..."},
 *   "keywords": [{"keyword": "...", "type": "origin|related", "monthly_shows": 120}],
 *   "faq": [{"question": "...", "answer": "...", "position": 0}],
 *   "links": [{"type": "website", "url": "https://..."}],
 *   "logo": {"filename": "logo.png", "content_base64": "..."},
 *   "external_id": 6203,        // dev brand.id — только аудит/лог
 *   "content_version": 3        // ≤ текущей версии на проде → skipped
 * }
 */
#[Route('/api/v1')]
class BrandIngestController extends AbstractController
{
    private const MAX_BODY_BYTES = 12 * 1024 * 1024; // логотип base64 + контент

    public function __construct(
        #[Autowire('%env(default::AGENT_API_TOKEN)%')]
        private readonly ?string $apiToken,
        #[Autowire('%env(default::AGENT_API_SECRET)%')]
        private readonly ?string $apiSecret,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/brands/upsert', name: 'api_brand_upsert', methods: ['POST'])]
    public function upsert(
        Request $request,
        BrandIngestService $ingest,
        RateLimiterFactory $agentApiLimiter,
    ): JsonResponse {
        if (($deny = $this->authorize($request, $agentApiLimiter)) !== null) {
            return $deny;
        }

        $body = (string) $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->json(['error' => 'payload too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'invalid json'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $ingest->upsert($payload);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('agent-api upsert failed', [
                'slug' => $payload['slug'] ?? null,
                'external_id' => $payload['external_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->logger->info('agent-api upsert', [
            'slug' => $payload['slug'] ?? null,
            'external_id' => $payload['external_id'] ?? null,
            'result' => $result['status'],
        ]);

        return $this->json($result);
    }

    /**
     * Обратный канал краудсорс-валидации (agent-pull: прод не достучится до LAN):
     * локальный агент поллит забракованные голосами точки и ре-обогащает их.
     */
    #[Route('/revalidation-queue', name: 'api_revalidation_queue', methods: ['GET'])]
    public function revalidationQueue(
        Request $request,
        EntityManagerInterface $em,
        RateLimiterFactory $agentApiLimiter,
    ): JsonResponse {
        if (($deny = $this->authorize($request, $agentApiLimiter, checkSignature: false)) !== null) {
            return $deny;
        }

        $items = [];
        foreach ($em->getRepository(\App\Entity\BrandDatapoint::class)->findQueuedForRevalidation(100) as $dp) {
            $items[] = [
                'brand_slug'   => $dp->getBrand()?->getSlug(),
                'target_type'  => $dp->getTargetType(),
                'target_id'    => $dp->getTargetId(),
                'field'        => $dp->getField(),
                'reject_count' => $dp->getRejectCount(),
                'queued_at'    => $dp->getQueuedRevalidateAt()?->format(DATE_ATOM),
            ];
        }

        return $this->json(['items' => $items]);
    }

    #[Route('/brands/{slug}/status', name: 'api_brand_status', methods: ['GET'])]
    public function status(
        string $slug,
        Request $request,
        EntityManagerInterface $em,
        RateLimiterFactory $agentApiLimiter,
    ): JsonResponse {
        if (($deny = $this->authorize($request, $agentApiLimiter, checkSignature: false)) !== null) {
            return $deny;
        }

        $brand = $em->getRepository(Brand::class)->findOneBy(['slug' => $slug]);
        if ($brand === null) {
            return $this->json(['exists' => false]);
        }

        return $this->json([
            'exists'          => true,
            'status'          => $brand->getStatus(),
            'publish_pending' => $brand->isPublishPending(),
            'published_at'    => $brand->getPublishedAt()?->format(DATE_ATOM),
            'content_version' => $brand->getContentVersion(),
        ]);
    }

    /** 401/403/429 либо null (доступ разрешён). Подпись тела — только для POST. */
    private function authorize(Request $request, RateLimiterFactory $limiter, bool $checkSignature = true): ?JsonResponse
    {
        if (!$limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->json(['error' => 'rate limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if ($this->apiToken === null || trim($this->apiToken) === '') {
            // API не сконфигурирован — закрыт наглухо (fail-closed).
            return $this->json(['error' => 'api disabled'], Response::HTTP_FORBIDDEN);
        }

        // Основной канал — X-Agent-Token (Authorization срезается веб-серверами без
        // fastcgi_param HTTP_AUTHORIZATION); Bearer оставлен как fallback.
        $token = (string) $request->headers->get('X-Agent-Token', '');
        if ($token === '') {
            $auth = (string) $request->headers->get('Authorization', '');
            $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        }
        if ($token === '' || !hash_equals($this->apiToken, $token)) {
            return $this->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if ($checkSignature) {
            $secret = (string) ($this->apiSecret ?? '');
            if ($secret === '') {
                return $this->json(['error' => 'api disabled'], Response::HTTP_FORBIDDEN);
            }
            $expected = hash_hmac('sha256', (string) $request->getContent(), $secret);
            if (!hash_equals($expected, (string) $request->headers->get('X-Signature', ''))) {
                return $this->json(['error' => 'bad signature'], Response::HTTP_UNAUTHORIZED);
            }
        }

        return null;
    }
}
