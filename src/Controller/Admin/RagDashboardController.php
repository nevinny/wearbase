<?php

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Админ-дашборд RAG-конвейера: что обрабатывается, где, сколько осталось.
 * Живые цифры из БД (демоны на LLM-сервере пишут сюда же); «за последний час»
 * считается от MAX(ts) самой стадии — TZ-безопасно (сервер пишет UTC, Mac — MSK).
 */
#[Route('/admin/rag', name: 'admin_rag')]
class RagDashboardController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly \Symfony\Contracts\HttpClient\HttpClientInterface $httpClient,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default::PROD_API_URL)%')]
        private readonly ?string $prodApiUrl,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default::AGENT_API_TOKEN)%')]
        private readonly ?string $agentToken,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default::AGENT_API_SECRET)%')]
        private readonly ?string $agentSecret,
    ) {
    }

    #[Route('', name: '')]
    public function index(): Response
    {
        $one = fn(string $sql, array $p = []) => (int) $this->db->fetchOne($sql, $p);
        $all = fn(string $sql) => $this->db->fetchAllKeyValue($sql);

        // --- Воронка брендов ---
        $brandStatuses = $all("SELECT status, COUNT(*) FROM brand GROUP BY status");
        $inPipeline = ($brandStatuses['active'] ?? 0) + ($brandStatuses['new'] ?? 0);

        // --- Статусы пайплайна ---
        $pipeline = $all("SELECT status, COUNT(*) FROM brand_rag_pipeline GROUP BY status");
        $pipeline['нет строки'] = max(0, $inPipeline - array_sum($pipeline));

        // --- Очередь URL ---
        $urlQueue = $all("SELECT status, COUNT(*) FROM brand_source_url GROUP BY status");

        // --- Стадии: сделано / за час (от MAX своей колонки) / последняя активность / осталось ---
        $stage = function (string $table, string $col, string $where = '1=1') use ($one): array {
            $max = $this->db->fetchOne("SELECT MAX({$col}) FROM {$table} WHERE {$where}");
            return [
                'done'     => $one("SELECT COUNT(*) FROM {$table} WHERE {$where} AND {$col} IS NOT NULL"),
                'lastHour' => $max ? $one("SELECT COUNT(*) FROM {$table} WHERE {$where} AND {$col} >= DATE_SUB(:m, INTERVAL 1 HOUR)", ['m' => $max]) : 0,
                'lastAt'   => $max ?: '—',
            ];
        };

        $stages = [
            'discover (сервер)' => $stage('brand_rag_pipeline', 'discovered_at')
                + ['left' => $one("SELECT COUNT(*) FROM brand b LEFT JOIN brand_rag_pipeline p ON p.brand_id=b.id WHERE b.status IN ('active','new') AND (p.id IS NULL OR p.discovered_at IS NULL)")],
            'crawl: own_site→own_page (сервер)' => $stage('brand_rag_pipeline', 'crawled_at')
                + ['left' => $one("SELECT COUNT(*) FROM brand b JOIN brand_rag_pipeline p ON p.brand_id=b.id WHERE b.status IN ('active','new') AND p.discovered_at IS NOT NULL AND p.crawl_status IS NULL")],
            'fetch (сервер)' => $stage('brand_source_url', 'fetched_at')
                + ['left' => (int) ($urlQueue['pending'] ?? 0) + (int) ($urlQueue['claimed'] ?? 0)],
            'embed (сервер, GPU)' => $stage('brand_rag_pipeline', 'embedded_at')
                + ['left' => $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE status='scraped'")],
            'generate (сервер, GPU)' => $stage('brand_rag_pipeline', 'generated_at')
                + ['left' => $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE status='embedded'")],
            'keywords (Mac, квота 100/ч)' => $stage('brand_rag_pipeline', 'keywords_checked_at')
                + ['left' => $one("SELECT COUNT(*) FROM brand b LEFT JOIN brand_rag_pipeline p ON p.brand_id=b.id LEFT JOIN brand_keyword k ON k.brand_id=b.id WHERE b.status IN ('active','new') AND k.id IS NULL AND (p.id IS NULL OR p.keywords_status IS NULL)")],
            'faq (сервер, GPU)' => [
                'done'     => $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE faq_status='done'"),
                'lastHour' => 0,
                'lastAt'   => $this->db->fetchOne("SELECT MAX(created_at) FROM brand_faq") ?: '—',
                'left'     => $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE status='done' AND faq_status IS NULL AND keywords_status IS NOT NULL"),
            ],
            'push → прод' => $stage('brand_rag_pipeline', 'pushed_at')
                + ['left' => $one("SELECT COUNT(*) FROM brand b JOIN brand_rag_pipeline p ON p.brand_id=b.id WHERE p.status='done' AND p.pushed_at IS NULL AND p.push_attempts < 3 AND p.faq_status IN ('done','skipped') AND p.keywords_status IN ('found','not_found') AND b.description IS NOT NULL AND b.description != '' AND b.meta_title IS NOT NULL AND b.meta_title != '' AND b.meta_description IS NOT NULL AND b.meta_description != ''"),
                   'waitsProd' => trim((string) $this->prodApiUrl) === ''],
            'publish-tick (прод, крон)' => $this->prodPublishStage(),
        ];

        // --- Срезы готовности ---
        $readiness = [
            'description+meta'    => $one("SELECT COUNT(*) FROM brand WHERE status IN ('active','new') AND description IS NOT NULL AND description != '' AND meta_title IS NOT NULL AND meta_title != ''"),
            'keywords found'      => $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE keywords_status='found'"),
            'keywords not_found'  => $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE keywords_status='not_found'"),
            'faq done'            => $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE faq_status='done'"),
            'faq skipped'         => $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE faq_status='skipped'"),
            'grounded генерация'  => $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE grounded=1"),
            'deferred (ждёт корпус)' => $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE status='deferred'"),
            'own_page (раскрыто краулом)' => $one("SELECT COUNT(*) FROM brand_source_url WHERE source_type='own_page'"),
            'брендов скраулено'   => $one("SELECT COUNT(*) FROM brand_rag_pipeline WHERE crawl_status='done'"),
            'документов корпуса'  => $one("SELECT COUNT(*) FROM brand_source_document"),
        ];

        // --- GSC ---
        $cohort = $this->db->fetchAssociative(
            "SELECT COUNT(s.id) checked, COALESCE(SUM(s.indexed),0) idx FROM brand b
             JOIN gsc_index_status s ON s.brand_id = b.id
             WHERE b.published_at IS NOT NULL AND b.published_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)",
        ) ?: ['checked' => 0, 'idx' => 0];
        $gsc = [
            'проверено страниц' => $one("SELECT COUNT(*) FROM gsc_index_status"),
            'в индексе Google'  => $one("SELECT COALESCE(SUM(indexed),0) FROM gsc_index_status"),
            'когорта 14д+ в индексе' => (int) $cohort['checked'] > 0
                ? sprintf('%d/%d (%.0f%%)', $cohort['idx'], $cohort['checked'], 100 * $cohort['idx'] / $cohort['checked'])
                : '— (нет когорты)',
            'последняя проверка' => $this->db->fetchOne("SELECT MAX(last_checked_at) FROM gsc_index_status") ?: '—',
            'строк аналитики'   => $one("SELECT COUNT(*) FROM gsc_page_stats"),
        ];

        // --- Outreach (письма) — данные на ПРОДЕ, тянем через агент-API ---
        $outreach = $this->prodOutreach();

        return $this->render('admin/rag_dashboard.html.twig', $this->viewParams($brandStatuses, $pipeline, $urlQueue, $stages, $readiness, $gsc, $outreach));
    }

    /**
     * Страница ручной верификации «подозрительных» брендов: контент-отказ модели
     * (status='review' — факты не о бренде / недостаточны, инцидент Majestic).
     * Владелец проходит список, сверяет данные и решает: переобогатить или скрыть.
     */
    #[Route('/review', name: '_review')]
    public function review(): Response
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT b.id, b.title, b.slug, b.email, b.description, b.status AS brand_status,
                   p.last_error,
                   (SELECT l.link_url FROM brand_link l
                      WHERE l.brand_id = b.id AND l.link_type = 'website' LIMIT 1) AS website
            FROM brand b
            JOIN brand_rag_pipeline p ON p.brand_id = b.id
            WHERE p.status = 'review'
            ORDER BY b.id
        SQL);

        return $this->render('admin/rag_review.html.twig', ['brands' => $rows]);
    }

    /** Действие из страницы ревью: requeue (переобогатить) | hide (скрыть из каталога). */
    #[Route('/review/{id}/{action}', name: '_review_action', methods: ['POST'], requirements: ['id' => '\d+', 'action' => 'requeue|hide'])]
    public function reviewAction(int $id, string $action, \Symfony\Component\HttpFoundation\Request $request): Response
    {
        if (!$this->isCsrfTokenValid('rag_review_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF-токен');
            return $this->redirectToRoute('admin_rag_review');
        }

        if ($action === 'requeue') {
            // Сброс на пересбор корпуса: discover подхватит (discovered_at IS NULL),
            // URL-очередь переоткрываем (fetched/failed → pending) для перечтения.
            $this->db->executeStatement(
                "UPDATE brand_rag_pipeline SET status='pending', discovered_at=NULL, scraped_at=NULL,
                 embedded_at=NULL, generated_at=NULL, source_count=0, has_own_site=NULL, last_error=NULL
                 WHERE brand_id = :id",
                ['id' => $id],
            );
            $this->db->executeStatement(
                "UPDATE brand_source_url SET status='pending' WHERE brand_id=:id AND status IN ('fetched','failed')",
                ['id' => $id],
            );
            $this->addFlash('success', "Бренд #{$id} отправлен на переобогащение");
        } else { // hide
            // Soft-hide локально (политика soft-delete) + снятие с публикации на проде.
            $slug = (string) $this->db->fetchOne("SELECT slug FROM brand WHERE id=:id", ['id' => $id]);
            $this->db->executeStatement("UPDATE brand SET status='inactive' WHERE id=:id", ['id' => $id]);
            $this->db->executeStatement("UPDATE brand_rag_pipeline SET last_error='review: скрыт вручную' WHERE brand_id=:id", ['id' => $id]);
            $this->addFlash('success', "Бренд #{$id} скрыт из каталога");

            // Прод чистим отдельным каналом (агент-API): резолв по slug (dev id ≠ прод).
            // Fail-soft: прод недоступен/не настроен → предупреждение, локальный hide уже применён.
            $this->addFlash(...$this->unpublishOnProd($slug, $id));
        }

        return $this->redirectToRoute('admin_rag_review');
    }

    /** Воронка email-активации владельцев брендов — прямой запрос к local-DB. */
    private function prodOutreach(): array
    {
        $row = $this->db->fetchAssociative(<<<'SQL'
            SELECT
                COUNT(*)                                        AS sent,
                SUM(o.delivered_at IS NOT NULL)                 AS delivered,
                SUM(o.first_opened_at  IS NOT NULL)             AS opened,
                SUM(o.first_clicked_at IS NOT NULL)             AS clicked,
                SUM(o.unsubscribed_at IS NOT NULL)              AS unsubscribed,
                SUM(o.bounced_at IS NOT NULL)                   AS bounced
            FROM brand_outreach o
            WHERE o.sent_at IS NOT NULL AND o.sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        SQL) ?: [];

        if ((int) ($row['sent'] ?? 0) === 0) {
            return ['отправлено' => 0, 'примечание' => 'писем пока нет'];
        }

        return [
            'отправлено (30д)' => (int) ($row['sent'] ?? 0),
            'доставлено'       => (int) ($row['delivered'] ?? 0),
            'открыто'          => (int) ($row['opened'] ?? 0),
            'кликов'           => (int) ($row['clicked'] ?? 0),
            'отписок/жалоб'    => (int) ($row['unsubscribed'] ?? 0) + (int) ($row['bounced'] ?? 0),
        ];
    }

    /**
     * Publish-данные живут ТОЛЬКО на проде (дрип-крон там) — тянем через агент-API.
     * Fail-soft: прод недоступен/не настроен → строка с пометкой, дашборд не падает.
     */
    private function prodPublishStage(): array
    {
        if (trim((string) $this->prodApiUrl) === '' || trim((string) $this->agentToken) === '') {
            return ['done' => 0, 'lastHour' => 0, 'lastAt' => '—', 'left' => 0, 'waitsProd' => true];
        }

        try {
            $data = $this->httpClient->request('GET', rtrim((string) $this->prodApiUrl, '/') . '/api/v1/publish-stats', [
                'headers' => ['X-Agent-Token' => (string) $this->agentToken],
                'timeout' => 4,
            ])->toArray(false);

            return [
                'done'     => (int) ($data['published_total'] ?? 0),
                'lastHour' => (int) ($data['published_today'] ?? 0), // колонка «за час» → «сегодня» для прод-строки
                'lastAt'   => ($data['last_published'] ?? null) ?: '—',
                'left'     => (int) ($data['queue_pending'] ?? 0),
            ];
        } catch (\Throwable) {
            return ['done' => 0, 'lastHour' => 0, 'lastAt' => 'прод недоступен', 'left' => 0, 'waitsProd' => true];
        }
    }

    /**
     * Снятие бренда с публикации на проде через агент-API /api/v1/brands/unpublish
     * (X-Agent-Token + HMAC-подпись тела, как PushBrandsCommand). Резолв по slug.
     * Fail-soft: возвращает [тип-flash, сообщение] — прод недоступен/не настроен →
     * предупреждение, локальный hide всё равно применён вызывающим кодом.
     *
     * @return array{0:string,1:string} [flashType, message]
     */
    private function unpublishOnProd(string $slug, int $id): array
    {
        if (trim((string) $this->prodApiUrl) === '' || trim((string) $this->agentToken) === '' || trim((string) $this->agentSecret) === '') {
            return ['warning', "Прод-API не настроен — бренд #{$id} НЕ снят с прод-каталога (только локально)"];
        }
        if ($slug === '') {
            return ['warning', "У бренда #{$id} нет slug — на прод снять не удалось (только локально)"];
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
                'unpublished' => ['success', "Бренд #{$id} снят с прод-каталога"],
                'not_found'   => ['warning', "Бренд #{$id} не найден на проде (ещё не публиковался)"],
                default       => ['warning', "Прод вернул неожиданный ответ — бренд #{$id} мог не сняться с прода"],
            };
        } catch (\Throwable $e) {
            return ['warning', "Прод недоступен ({$e->getMessage()}) — бренд #{$id} НЕ снят с прод-каталога (только локально)"];
        }
    }

    /** @return array<string,mixed> */
    private function viewParams(array $brandStatuses, array $pipeline, array $urlQueue, array $stages, array $readiness, array $gsc, array $outreach = []): array
    {
        return [
            'brandStatuses' => $brandStatuses,
            'pipeline'      => $pipeline,
            'urlQueue'      => $urlQueue,
            'stages'        => $stages,
            'readiness'     => $readiness,
            'gsc'           => $gsc,
            'outreach'      => $outreach,
        ];
    }
}
