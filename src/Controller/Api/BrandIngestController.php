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
 *   "attributes": [{"name": "Стиль", "value": "Кэжуал"}],
 *   "stores": [{"address": "ул. Тверская, 1", "city": "Москва", "phone": "+7...", "workHours": "пн–пт 10–20"}],
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
     * Снятие бренда с публикации (point 1 чистки прода): админ-кнопка «⊘ Скрыть»
     * в /admin/rag/review дёргает этот endpoint. Та же auth, что у upsert
     * (X-Agent-Token + HMAC-подпись тела). Soft — статус в Disabled, не delete.
     *
     * Тело (application/json): {"slug": "..."} либо {"brand_id": N}.
     * Ответ: {"status":"unpublished","brand_id":N} | {"status":"not_found","brand_id":null}.
     */
    #[Route('/brands/unpublish', name: 'api_brand_unpublish', methods: ['POST'])]
    public function unpublish(
        Request $request,
        BrandIngestService $ingest,
        RateLimiterFactory $agentApiLimiter,
    ): JsonResponse {
        if (($deny = $this->authorize($request, $agentApiLimiter)) !== null) {
            return $deny;
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'invalid json'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $ingest->unpublish($payload);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('agent-api unpublish failed', [
                'slug'     => $payload['slug'] ?? null,
                'brand_id' => $payload['brand_id'] ?? null,
                'error'    => $e->getMessage(),
            ]);

            return $this->json(['error' => 'internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->logger->info('agent-api unpublish', [
            'slug'   => $payload['slug'] ?? null,
            'result' => $result['status'],
        ]);

        return $this->json($result);
    }

    /**
     * Приоритетная публикация (point: ручные/важные бренды). Активирует бренд сразу,
     * минуя случайную выборку дрип-крона; published_at учитывается в дневном таргете
     * ramp'а. Та же auth, что у upsert/unpublish (X-Agent-Token + HMAC-подпись тела).
     *
     * Тело (application/json): {"slug": "..."} либо {"brand_id": N}.
     * Ответ: {"status":"published|already_published|not_found","brand_id":N,"url":"..."}.
     */
    #[Route('/brands/publish', name: 'api_brand_publish', methods: ['POST'])]
    public function publish(
        Request $request,
        BrandIngestService $ingest,
        RateLimiterFactory $agentApiLimiter,
        \App\Service\IndexNowPinger $indexNow,
    ): JsonResponse {
        if (($deny = $this->authorize($request, $agentApiLimiter)) !== null) {
            return $deny;
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'invalid json'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $ingest->publish($payload);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('agent-api publish failed', [
                'slug'     => $payload['slug'] ?? null,
                'brand_id' => $payload['brand_id'] ?? null,
                'error'    => $e->getMessage(),
            ]);

            return $this->json(['error' => 'internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // IndexNow — как в дрип-тике: мгновенный пинг Яндексу/Bing о новом URL.
        if ($result['status'] === 'published' && isset($result['url'])) {
            $indexNow->ping([$result['url']]);
        }

        $this->logger->info('agent-api publish', [
            'slug'   => $payload['slug'] ?? null,
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

    /** Статистика дрип-публикации для dev-дашборда (publish-данные живут только на проде). */
    #[Route('/publish-stats', name: 'api_publish_stats', methods: ['GET'])]
    public function publishStats(
        Request $request,
        EntityManagerInterface $em,
        RateLimiterFactory $agentApiLimiter,
    ): JsonResponse {
        if (($deny = $this->authorize($request, $agentApiLimiter, checkSignature: false)) !== null) {
            return $deny;
        }

        $db = $em->getConnection();
        $todayMsk = (new \DateTime('today', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i:s');

        return $this->json([
            'published_total' => (int) $db->fetchOne('SELECT COUNT(*) FROM brand WHERE published_at IS NOT NULL'),
            'published_today' => (int) $db->fetchOne('SELECT COUNT(*) FROM brand WHERE published_at >= ?', [$todayMsk]),
            'queue_pending'   => (int) $db->fetchOne("SELECT COUNT(*) FROM brand WHERE status='new' AND publish_pending=1"),
            'last_published'  => $db->fetchOne('SELECT MAX(published_at) FROM brand') ?: null,
        ]);
    }

    /**
     * Вебхук SMTP-сервиса (RuSender): bounce/complaint/unsub. Токен в query
     * (Authorization срезается). Маппинг устойчив к форматам; hard bounce →
     * bounced_at (suppression), soft → last_error (retryable). Апдейты ПО EMAIL.
     */
    #[Route('/email/webhook', name: 'api_email_webhook', methods: ['POST'])]
    public function emailWebhook(
        Request $request,
        EntityManagerInterface $em,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default::OUTREACH_WEBHOOK_TOKEN)%')]
        ?string $webhookToken,
    ): JsonResponse {
        if ($webhookToken === null || trim($webhookToken) === ''
            || !hash_equals($webhookToken, (string) $request->query->get('token', ''))) {
            return $this->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $p = json_decode((string) $request->getContent(), true);
        if (!is_array($p)) {
            return $this->json(['error' => 'invalid json'], Response::HTTP_BAD_REQUEST);
        }
        $events = isset($p['type']) || isset($p['event']) ? [$p] : ($p['events'] ?? $p['data'] ?? [$p]);

        $db = $em->getConnection();
        foreach ($events as $e) {
            if (!is_array($e)) {
                continue;
            }
            $type   = strtolower((string) ($e['type'] ?? $e['event'] ?? $e['eventType'] ?? ''));
            $email  = (string) ($e['email'] ?? $e['recipient'] ?? $e['to'] ?? ($e['data']['email'] ?? ''));
            $reason = mb_substr((string) ($e['reason'] ?? $e['description'] ?? $e['bounceType'] ?? ''), 0, 500);
            if ($email === '') {
                continue;
            }

            // Номенклатура RuSender (рус.) + универсальные англ. кандидаты.
            $isPermanent = str_contains($type, 'hard') || str_contains($reason, 'permanent') || preg_match('~\b5\.\d\.\d~', $reason);
            if ((str_contains($type, 'bounce') && $isPermanent)
                || in_array($type, ['failed', 'dropped', 'rejected', 'undeliverable'], true)
                || str_contains($type, 'не существует') || str_contains($type, 'not_exist') || str_contains($type, 'unknown_user')) {
                $db->executeStatement('UPDATE brand_outreach SET bounced_at = COALESCE(bounced_at, NOW()) WHERE email = :e', ['e' => $email]);
            } elseif (str_contains($type, 'bounce') || str_contains($type, 'deferred')
                || str_contains($type, 'переполнен') || str_contains($type, 'недоступен')
                || str_contains($type, 'overflow') || str_contains($type, 'unavailable')) {
                $db->executeStatement('UPDATE brand_outreach SET last_error = :r WHERE email = :e', ['r' => 'soft bounce: ' . ($reason ?: $type), 'e' => $email]);
            } elseif (str_contains($type, 'complain') || str_contains($type, 'spam') || str_contains($type, 'abuse') || str_contains($type, 'жалоба')
                || str_contains($type, 'unsub') || str_contains($type, 'optout') || str_contains($type, 'отписка')) {
                $db->executeStatement('UPDATE brand_outreach SET unsubscribed_at = COALESCE(unsubscribed_at, NOW()) WHERE email = :e', ['e' => $email]);
            } elseif (str_contains($type, 'deliver') || str_contains($type, 'доставлено')) {
                $db->executeStatement('UPDATE brand_outreach SET delivered_at = COALESCE(delivered_at, NOW()) WHERE email = :e', ['e' => $email]);
            }
            // open/click — игнорируем: свой пиксель и /e/c надёжнее
        }

        return $this->json(['ok' => true]); // всегда 200 на валидный токен — иначе сервис ретраит
    }

    /** Воронка активации для dev-дашборда/отчёта: когорты отправки 7/14/30д (нарастающие окна). */
    #[Route('/outreach-stats', name: 'api_outreach_stats', methods: ['GET'])]
    public function outreachStats(
        Request $request,
        EntityManagerInterface $em,
        RateLimiterFactory $agentApiLimiter,
    ): JsonResponse {
        if (($deny = $this->authorize($request, $agentApiLimiter, checkSignature: false)) !== null) {
            return $deny;
        }

        $rows = $em->getConnection()->fetchAllAssociative(<<<'SQL'
            SELECT d.label,
                   COUNT(*)                                        AS sent,
                   SUM(o.delivered_at IS NOT NULL)                 AS delivered,
                   SUM(o.first_opened_at  IS NOT NULL)             AS opened,
                   SUM(o.first_clicked_at IS NOT NULL)             AS clicked,
                   SUM(EXISTS(SELECT 1 FROM brand_claim c
                              WHERE c.brand_id = o.brand_id AND c.created_at > o.sent_at)) AS claimed,
                   SUM(EXISTS(SELECT 1 FROM subscription s
                              WHERE s.brand_id = o.brand_id AND s.created_at > o.sent_at)) AS subscribed,
                   SUM(o.unsubscribed_at IS NOT NULL)              AS unsubscribed,
                   SUM(o.bounced_at IS NOT NULL)                   AS bounced
            FROM (SELECT 7 AS days, '7d' AS label
                  UNION ALL SELECT 14, '14d'
                  UNION ALL SELECT 30, '30d') d
            JOIN brand_outreach o
              ON o.sent_at IS NOT NULL AND o.sent_at >= (NOW() - INTERVAL d.days DAY)
            GROUP BY d.label, d.days
            ORDER BY d.days
        SQL);

        return $this->json(['cohorts' => $rows, 'note' => 'окна нарастающие (7⊂14⊂30); KPI — клики, opens завышены']);
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
