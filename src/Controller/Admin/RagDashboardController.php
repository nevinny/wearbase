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
    public function __construct(private readonly Connection $db)
    {
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
                + ['left' => $one("SELECT COUNT(*) FROM brand b JOIN brand_rag_pipeline p ON p.brand_id=b.id WHERE p.status='done' AND p.pushed_at IS NULL AND p.push_attempts < 3 AND p.faq_status IN ('done','skipped') AND p.keywords_status IN ('found','not_found') AND b.description IS NOT NULL AND b.description != '' AND b.meta_title IS NOT NULL AND b.meta_title != '' AND b.meta_description IS NOT NULL AND b.meta_description != ''")],
            'publish-tick (прод, крон)' => $stage('brand', 'published_at')
                + ['left' => $one("SELECT COUNT(*) FROM brand WHERE status='new' AND publish_pending=1")],
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
            'документов корпуса'  => $one("SELECT COUNT(*) FROM brand_source_document"),
        ];

        // --- GSC ---
        $gsc = [
            'проверено страниц' => $one("SELECT COUNT(*) FROM gsc_index_status"),
            'в индексе Google'  => $one("SELECT COALESCE(SUM(indexed),0) FROM gsc_index_status"),
            'последняя проверка' => $this->db->fetchOne("SELECT MAX(last_checked_at) FROM gsc_index_status") ?: '—',
            'строк аналитики'   => $one("SELECT COUNT(*) FROM gsc_page_stats"),
        ];

        return $this->render('admin/rag_dashboard.html.twig', [
            'brandStatuses' => $brandStatuses,
            'pipeline'      => $pipeline,
            'urlQueue'      => $urlQueue,
            'stages'        => $stages,
            'readiness'     => $readiness,
            'gsc'           => $gsc,
        ]);
    }
}
