<?php

namespace App\Controller\Admin;

use App\Entity\Brand;
use App\Entity\BrandRagPipeline;
use App\Entity\BrandSourceDocument;
use App\Entity\BrandSourceUrl;
use App\Repository\BrandRepository;
use App\Service\VectorStoreService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Factory\AdminContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
        private readonly EntityManagerInterface $em,
        private readonly BrandRepository $brands,
        private readonly VectorStoreService $vectors,
        private readonly AdminContextFactory $adminContextFactory,
        private readonly DashboardController $dashboard,
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
    public function index(Request $request): Response
    {
        $this->initAdminContext($request);
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
                + ['left' => $this->brands->countReadyToPush(),
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

        // --- GSC --- (таблицы gsc_* живут на проде; на dev их может не быть → fail-soft)
        $gsc = $this->gscStats();

        // --- Outreach (письма) — данные на ПРОДЕ, тянем через агент-API ---
        $outreach = $this->prodOutreach();

        return $this->render('admin/rag_dashboard.html.twig', $this->viewParams($brandStatuses, $pipeline, $urlQueue, $stages, $readiness, $gsc, $outreach));
    }

    /**
     * Живая визуализация конвейера: горизонтальный «конвейер» этапов со «стопками»
     * (сколько ждёт перед этапом) + темпом/ч + лентой реальных переходов брендов.
     * Трассировка пути бренда — из per-stage таймстемпов (новых таблиц не нужно).
     * Сама страница — лёгкий shell; данные тянет JS-поллингом с flow.json.
     */
    #[Route('/flow', name: '_flow', methods: ['GET'])]
    public function flow(Request $request): Response
    {
        $this->initAdminContext($request);
        return $this->render('admin/rag_flow.html.twig', ['data' => $this->buildFlowData()]);
    }

    #[Route('/flow.json', name: '_flow_data', methods: ['GET'])]
    public function flowData(): Response
    {
        return $this->json($this->buildFlowData());
    }

    /**
     * @return array{stages: list<array{key:string,label:string,stack:int,perHour:int,lastAt:?string}>,
     *               recent: list<array<string,mixed>>}
     */
    private function buildFlowData(): array
    {
        $one = fn(string $sql) => (int) $this->db->fetchOne($sql);

        // Этапы конвейера. ГЛАВНАЯ метрика — `stack`: сколько брендов ЖДЁТ обработки этим этапом
        // (очередь). Темп «сделано/ч» намеренно НЕ показываем (не интересует + вводил в заблуждение
        // на generate, где done пишется редко, а deferred — массово).
        // lane: net (Mac/сеть) | gpu (зовёт LLM .119). role: main (магистраль) | outcome (ветка-исход
        // generate: deferred/review) | side (побочное обогащение). next — для анимации перехода.
        $stages = [
            ['key' => 'discover', 'label' => 'discover', 'lane' => 'net', 'role' => 'main', 'next' => 'crawl', 'stack' => $one(
                "SELECT COUNT(*) FROM brand b LEFT JOIN brand_rag_pipeline p ON p.brand_id=b.id
                 WHERE b.status IN ('active','new') AND (p.id IS NULL OR p.discovered_at IS NULL)"
            )],
            ['key' => 'crawl', 'label' => 'crawl', 'lane' => 'net', 'role' => 'main', 'next' => 'fetch', 'stack' => $one(
                "SELECT COUNT(*) FROM brand b JOIN brand_rag_pipeline p ON p.brand_id=b.id
                 WHERE b.status IN ('active','new') AND p.discovered_at IS NOT NULL AND p.crawl_status IS NULL"
            )],
            ['key' => 'fetch', 'label' => 'fetch', 'lane' => 'net', 'role' => 'main', 'next' => 'embed', 'stack' => $one(
                "SELECT COUNT(*) FROM brand_rag_pipeline WHERE status='pending'"
            )],
            ['key' => 'embed', 'label' => 'embed', 'lane' => 'gpu', 'role' => 'main', 'next' => 'generate', 'stack' => $one(
                "SELECT COUNT(*) FROM brand_rag_pipeline WHERE status='scraped'"
            )],
            ['key' => 'generate', 'label' => 'generate', 'lane' => 'gpu', 'role' => 'main', 'next' => 'push', 'stack' => $one(
                "SELECT COUNT(*) FROM brand_rag_pipeline WHERE status='embedded'"
            )],
            // Ветки-исходы generate (видимые очереди, а не «сделано»):
            ['key' => 'deferred', 'label' => 'deferred', 'lane' => 'gpu', 'role' => 'outcome', 'next' => null, 'stack' => $one(
                "SELECT COUNT(*) FROM brand_rag_pipeline WHERE status='deferred'"
            )],
            ['key' => 'review', 'label' => 'review', 'lane' => 'gpu', 'role' => 'outcome', 'next' => null, 'stack' => $one(
                "SELECT COUNT(*) FROM brand_rag_pipeline WHERE status='review'"
            )],
            ['key' => 'enrich', 'label' => 'enrich', 'lane' => 'gpu', 'role' => 'side', 'next' => null, 'stack' => $one(
                "SELECT COUNT(*) FROM brand WHERE status IN ('active','new') AND contact_enriched_at IS NULL"
            )],
            ['key' => 'faq', 'label' => 'faq', 'lane' => 'gpu', 'role' => 'side', 'next' => null, 'stack' => $one(
                "SELECT COUNT(*) FROM brand_rag_pipeline WHERE status='done' AND faq_status IS NULL AND keywords_status IS NOT NULL"
            )],
            ['key' => 'extract', 'label' => 'extract', 'lane' => 'gpu', 'role' => 'side', 'next' => null, 'stack' => $one(
                "SELECT COUNT(*) FROM brand_rag_pipeline WHERE source_count > 0 AND attributes_status IS NULL
                 AND status IN ('scraped','embedded','generated','done')"
            )],
            ['key' => 'logo', 'label' => 'logo', 'lane' => 'net', 'role' => 'side', 'next' => null, 'stack' => $one(
                "SELECT COUNT(*) FROM brand b LEFT JOIN brand_rag_pipeline p ON p.brand_id=b.id
                 WHERE b.status IN ('active','new') AND (b.logo IS NULL OR b.logo='')
                   AND (p.id IS NULL OR p.logo_status IS NULL OR p.logo_status='failed')"
            )],
            // keywords — отдельный квотируемый демон (Yandex Wordstat 100/ч), не в gpu/net-наборах
            ['key' => 'keywords', 'label' => 'keywords', 'lane' => 'net', 'role' => 'side', 'next' => null, 'stack' => $one(
                "SELECT COUNT(*) FROM brand b LEFT JOIN brand_rag_pipeline p ON p.brand_id=b.id
                 LEFT JOIN brand_keyword k ON k.brand_id=b.id
                 WHERE b.status IN ('active','new') AND k.id IS NULL AND (p.id IS NULL OR p.keywords_status IS NULL)"
            )],
            ['key' => 'push', 'label' => 'push', 'lane' => 'net', 'role' => 'main', 'next' => null,
             'stack' => $this->brands->countReadyToPush()],
        ];

        // Лента реальных переходов: последние 40 событий «бренд завершил этап X».
        // Union per-stage таймстемпов = точная трасса каждого бренда (ORDER BY at —
        // без NOW(), поэтому TZ-перекос максимум переставит соседей, не врёт принципиально).
        $recent = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT t.brand_id AS brandId, t.stage, t.at AS at, b.title
            FROM (
                SELECT brand_id, 'discover' stage, discovered_at  at FROM brand_rag_pipeline WHERE discovered_at  IS NOT NULL
                UNION ALL SELECT brand_id, 'crawl',    crawled_at      FROM brand_rag_pipeline WHERE crawled_at      IS NOT NULL
                UNION ALL SELECT brand_id, 'fetch',    scraped_at      FROM brand_rag_pipeline WHERE scraped_at      IS NOT NULL
                UNION ALL SELECT brand_id, 'embed',    embedded_at     FROM brand_rag_pipeline WHERE embedded_at     IS NOT NULL
                UNION ALL SELECT brand_id, 'generate', generated_at    FROM brand_rag_pipeline WHERE generated_at    IS NOT NULL
                UNION ALL SELECT brand_id, 'extract',  extracted_at    FROM brand_rag_pipeline WHERE extracted_at    IS NOT NULL
                UNION ALL SELECT brand_id, 'logo',     logo_checked_at FROM brand_rag_pipeline WHERE logo_checked_at IS NOT NULL
                UNION ALL SELECT brand_id, 'push',     pushed_at       FROM brand_rag_pipeline WHERE pushed_at       IS NOT NULL
                UNION ALL SELECT brand_id, 'keywords', keywords_checked_at FROM brand_rag_pipeline WHERE keywords_checked_at IS NOT NULL
                UNION ALL SELECT id,       'enrich',   contact_enriched_at FROM brand WHERE contact_enriched_at IS NOT NULL
            ) t
            JOIN brand b ON b.id = t.brand_id
            ORDER BY t.at DESC
            LIMIT 40
            SQL);

        return ['stages' => $stages, 'recent' => $recent];
    }

    /**
     * Страница ручной верификации «подозрительных» брендов: контент-отказ модели
     * (status='review' — факты не о бренде / недостаточны, инцидент Majestic).
     * Владелец проходит список, сверяет данные и решает: переобогатить или скрыть.
     */
    #[Route('/review', name: '_review')]
    public function review(Request $request): Response
    {
        $this->initAdminContext($request);
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
            $this->db->executeStatement("UPDATE brand SET status='disabled', publish_pending=0 WHERE id=:id", ['id' => $id]);
            $this->db->executeStatement("UPDATE brand_rag_pipeline SET last_error='review: скрыт вручную' WHERE brand_id=:id", ['id' => $id]);
            $this->addFlash('success', "Бренд #{$id} скрыт из каталога");

            // Прод чистим отдельным каналом (агент-API): резолв по slug (dev id ≠ прод).
            // Fail-soft: прод недоступен/не настроен → предупреждение, локальный hide уже применён.
            $this->addFlash(...$this->unpublishOnProd($slug, $id));
        }

        return $this->redirectToRoute('admin_rag_review');
    }

    // =====================================================================
    //  Панель управления конвейером по одному бренду («подсказывать вручную»)
    // =====================================================================

    /** Поиск бренда (id / slug / название) → редирект на его панель. */
    #[Route('/brand', name: '_brand', methods: ['GET'])]
    public function brandLookup(Request $request): Response
    {
        $this->initAdminContext($request);
        $q = trim((string) $request->query->get('q', ''));
        if ($q === '') {
            return $this->render('admin/rag_brand_search.html.twig', ['q' => '', 'results' => []]);
        }

        if (ctype_digit($q)) {
            if ($this->db->fetchOne('SELECT id FROM brand WHERE id = :id', ['id' => (int) $q])) {
                return $this->redirectToRoute('admin_rag_brand_panel', ['id' => (int) $q]);
            }
        }

        $results = $this->db->fetchAllAssociative(
            'SELECT id, title, slug, status FROM brand WHERE slug = :q OR title LIKE :like ORDER BY (slug = :q) DESC, title ASC LIMIT 25',
            ['q' => $q, 'like' => '%' . $q . '%'],
        );

        if (count($results) === 1) {
            return $this->redirectToRoute('admin_rag_brand_panel', ['id' => (int) $results[0]['id']]);
        }

        return $this->render('admin/rag_brand_search.html.twig', ['q' => $q, 'results' => $results]);
    }

    /** Панель бренда: статусы всех этапов, очередь URL, корпус, формы ручного ввода. */
    #[Route('/brand/{id}', name: '_brand_panel', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function brandPanel(int $id, Request $request): Response
    {
        $this->initAdminContext($request);
        $brand = $this->db->fetchAssociative(
            'SELECT id, title, slug, status, city, email, description, meta_title, meta_description,
                    contact_status, contact_attempts, logo
             FROM brand WHERE id = :id',
            ['id' => $id],
        );
        if ($brand === false) {
            throw $this->createNotFoundException("Бренд #{$id} не найден");
        }

        $pipeline = $this->db->fetchAssociative('SELECT * FROM brand_rag_pipeline WHERE brand_id = :id', ['id' => $id]) ?: null;

        $urls = $this->db->fetchAllAssociative(
            'SELECT id, status, source_type, tier, relevance_score, url, attempts, last_error, fetched_at
             FROM brand_source_url WHERE brand_id = :id ORDER BY tier ASC, relevance_score DESC, id ASC',
            ['id' => $id],
        );

        $docs = $this->db->fetchAllAssociative(
            'SELECT id, source_type, relevance_score, char_count, embedded, http_status, url, created_at, deleted_at
             FROM brand_source_document WHERE brand_id = :id ORDER BY id ASC',
            ['id' => $id],
        );

        // Чанки в Qdrant — fail-soft (сервер может быть недоступен).
        try {
            $chunkCount = $this->vectors->countByBrand($id);
        } catch (\Throwable) {
            $chunkCount = null;
        }

        return $this->render('admin/rag_brand.html.twig', [
            'brand'      => $brand,
            'pipeline'   => $pipeline,
            'urls'       => $urls,
            'docs'       => $docs,
            'chunkCount' => $chunkCount,
            'urlTypes'   => [
                BrandSourceUrl::TYPE_OWN_SITE, BrandSourceUrl::TYPE_MARKETPLACE,
                BrandSourceUrl::TYPE_CATALOG, BrandSourceUrl::TYPE_ARTICLE_REVIEW,
                BrandSourceUrl::TYPE_SOCIAL, BrandSourceUrl::TYPE_MENTION,
            ],
        ]);
    }

    /** Вставка готового факт-текста → brand_source_document (manual) → ставит на embed. */
    #[Route('/brand/{id}/fact', name: '_brand_fact', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function brandAddFact(int $id, Request $request): Response
    {
        $brand = $this->requireBrandCsrf($id, $request);
        if (!$brand instanceof Brand) {
            return $brand; // redirect (ошибка CSRF/не найден)
        }

        $text = trim((string) $request->request->get('fact'));
        if (mb_strlen($text) < 20) {
            $this->addFlash('danger', 'Слишком короткий факт (нужно ≥20 символов).');
            return $this->redirectToRoute('admin_rag_brand_panel', ['id' => $id]);
        }

        // Дедуп по (brand_id, content_hash): один и тот же текст не плодим.
        $hash = hash('sha256', $text);
        $dup = $this->db->fetchOne(
            'SELECT id FROM brand_source_document WHERE brand_id = :id AND content_hash = :h',
            ['id' => $id, 'h' => $hash],
        );
        if ($dup) {
            $this->addFlash('warning', 'Такой факт уже есть в корпусе (#' . $dup . ').');
            return $this->redirectToRoute('admin_rag_brand_panel', ['id' => $id]);
        }

        $doc = (new BrandSourceDocument())
            ->setBrand($brand)
            ->setUrl('manual://admin')
            ->setSourceType(BrandSourceUrl::TYPE_OWN_SITE) // авторитетный источник: приоритет в retrieve
            ->setRelevanceScore(1.0)
            ->setCleanText($text)
            ->setEmbedded(false);
        $this->em->persist($doc);

        // Ставим бренд на embed: pipeline → scraped (embed-стадия берёт status='scraped').
        $p = $this->pipelineFor($brand);
        $p->setStatus(BrandRagPipeline::STATUS_SCRAPED)
          ->setEmbeddedAt(null)
          ->setContentChangedAt(new \DateTime())
          ->setLastError(null);

        $this->em->flush();

        $this->addFlash('success', 'Факт добавлен в корпус, бренд поставлен на embed → generate (демон, ближайший цикл).');
        $this->addFlash('warning', 'Для grounded-генерации gate требует ≥3 чанков (~4000+ символов). Если суммарного текста мало — бренд уйдёт в deferred. Добавьте развёрнутый текст или ещё факты.');
        return $this->redirectToRoute('admin_rag_brand_panel', ['id' => $id]);
    }

    /** Добавление URL-источника в очередь discover→fetch вручную. */
    #[Route('/brand/{id}/url', name: '_brand_url', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function brandAddUrl(int $id, Request $request): Response
    {
        $brand = $this->requireBrandCsrf($id, $request);
        if (!$brand instanceof Brand) {
            return $brand;
        }

        $url  = trim((string) $request->request->get('url'));
        $type = (string) $request->request->get('source_type', BrandSourceUrl::TYPE_OWN_SITE);

        if (filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $url)) {
            $this->addFlash('danger', 'Некорректный URL (нужен http(s)://…).');
            return $this->redirectToRoute('admin_rag_brand_panel', ['id' => $id]);
        }

        $allowed = [
            BrandSourceUrl::TYPE_OWN_SITE, BrandSourceUrl::TYPE_MARKETPLACE,
            BrandSourceUrl::TYPE_CATALOG, BrandSourceUrl::TYPE_ARTICLE_REVIEW,
            BrandSourceUrl::TYPE_SOCIAL, BrandSourceUrl::TYPE_MENTION,
        ];
        if (!in_array($type, $allowed, true)) {
            $type = BrandSourceUrl::TYPE_MENTION;
        }

        // Дедуп по (brand_id, url_hash) — как в discover.
        $hash = BrandSourceUrl::normalizeHash($url);
        if ($this->db->fetchOne('SELECT id FROM brand_source_url WHERE brand_id = :id AND url_hash = :h', ['id' => $id, 'h' => $hash])) {
            $this->addFlash('warning', 'Такой URL уже в очереди источников.');
            return $this->redirectToRoute('admin_rag_brand_panel', ['id' => $id]);
        }

        $u = (new BrandSourceUrl())
            ->setBrand($brand)
            ->setUrl($url)
            ->setSourceType($type)
            ->setTier($this->tierForType($type))
            ->setRelevanceScore(0.9) // ручной ввод = доверенный
            ->setStatus(BrandSourceUrl::STATUS_PENDING);
        $this->em->persist($u);
        $this->em->flush();

        $this->addFlash('success', 'URL добавлен в очередь. Заберёт app:brand:fetch (демон), затем embed → generate.');
        return $this->redirectToRoute('admin_rag_brand_panel', ['id' => $id]);
    }

    /** Правка названия/slug + сброс на повторный discover (фикс транслита и т.п.). */
    #[Route('/brand/{id}/rename', name: '_brand_rename', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function brandRename(int $id, Request $request): Response
    {
        $brand = $this->requireBrandCsrf($id, $request);
        if (!$brand instanceof Brand) {
            return $brand;
        }

        $title = trim((string) $request->request->get('title'));
        $slug  = trim((string) $request->request->get('slug'));
        if ($title === '' || $slug === '') {
            $this->addFlash('danger', 'Название и slug не могут быть пустыми.');
            return $this->redirectToRoute('admin_rag_brand_panel', ['id' => $id]);
        }

        if ($slug !== $brand->getSlug()
            && $this->db->fetchOne('SELECT id FROM brand WHERE slug = :s AND id <> :id', ['s' => $slug, 'id' => $id])) {
            $this->addFlash('danger', "Slug «{$slug}» уже занят другим брендом.");
            return $this->redirectToRoute('admin_rag_brand_panel', ['id' => $id]);
        }

        $brand->setTitle($title)->setSlug($slug);

        // Сброс на повторный discover с исправленным именем.
        $p = $this->pipelineFor($brand);
        $p->setStatus(BrandRagPipeline::STATUS_PENDING)
          ->setDiscoveredAt(null)
          ->setHasOwnSite(null)
          ->setLastError(null);

        $this->em->flush();

        $this->addFlash('success', 'Бренд переименован. Поставлен на повторный discover с новым названием.');
        return $this->redirectToRoute('admin_rag_brand_panel', ['id' => $id]);
    }

    /** Перезапуск конкретного этапа: сбрасывает состояние, демон обработает заново. */
    #[Route('/brand/{id}/rerun/{stage}', name: '_brand_rerun', methods: ['POST'], requirements: ['id' => '\d+', 'stage' => 'discover|fetch|embed|generate|contacts|logo'])]
    public function brandRerun(int $id, string $stage, Request $request): Response
    {
        $brand = $this->requireBrandCsrf($id, $request);
        if (!$brand instanceof Brand) {
            return $brand;
        }

        // Контакты — поля бренда, не pipeline. Демон зовёт enrich-contacts без --force,
        // поэтому, чтобы он переобработал даже терминальный not_found, ставим бренд в
        // его link-агностичную ветку finder'а (contactStatus='error' AND attempts<3).
        if ($stage === 'contacts') {
            $this->db->executeStatement(
                "UPDATE brand SET contact_status='error', contact_attempts=0 WHERE id = :id",
                ['id' => $id],
            );
            $this->addFlash('success', 'Контакты поставлены на переобогащение (демон, с обходом not_found). Статус временно «error», демон перепишет его.');
            return $this->redirectToRoute('admin_rag_brand_panel', ['id' => $id]);
        }

        $p = $this->pipelineFor($brand);

        switch ($stage) {
            case 'discover':
                $p->setStatus(BrandRagPipeline::STATUS_PENDING)->setDiscoveredAt(null)->setHasOwnSite(null)->setLastError(null);
                break;
            case 'fetch':
                // Переоткрываем уже обработанные URL для повторного скрейпа.
                $this->db->executeStatement(
                    "UPDATE brand_source_url SET status='pending', attempts=0, last_error=NULL WHERE brand_id = :id AND status IN ('fetched','failed','skipped')",
                    ['id' => $id],
                );
                break;
            case 'embed':
                // Полный пере-embed: помечаем документы неэмбеддженными + status=scraped.
                $this->db->executeStatement('UPDATE brand_source_document SET embedded = 0 WHERE brand_id = :id', ['id' => $id]);
                $p->setStatus(BrandRagPipeline::STATUS_SCRAPED)->setEmbeddedAt(null)->setLastError(null);
                break;
            case 'generate':
                $p->setStatus(BrandRagPipeline::STATUS_EMBEDDED)->setGeneratedAt(null)->setLastError(null);
                break;
            case 'logo':
                // Сброс поиска логотипа: logo_status=NULL → findForLogo снова подхватит
                // (даже терминальные not_found/skipped). Бренд должен быть без logo.
                $p->setLogoStatus(null)->setLogoCheckedAt(null);
                break;
        }

        $this->em->flush();

        $this->addFlash('success', "Этап «{$stage}» сброшен — демон перезапустит его в ближайшем цикле.");
        return $this->redirectToRoute('admin_rag_brand_panel', ['id' => $id]);
    }

    /** Soft-skip нерелевантного URL: статус skipped (не рефетчим; dedup не даст discover'у вернуть его). */
    #[Route('/brand/{id}/url/{urlId}/skip', name: '_brand_url_skip', methods: ['POST'], requirements: ['id' => '\d+', 'urlId' => '\d+'])]
    public function brandSkipUrl(int $id, int $urlId, Request $request): Response
    {
        $brand = $this->requireBrandCsrf($id, $request);
        if (!$brand instanceof Brand) {
            return $brand;
        }

        $affected = $this->db->executeStatement(
            "UPDATE brand_source_url SET status='skipped', last_error='skipped вручную в админке' WHERE id = :uid AND brand_id = :bid",
            ['uid' => $urlId, 'bid' => $id],
        );
        $this->addFlash($affected ? 'success' : 'warning', $affected ? "URL #{$urlId} помечен skipped." : "URL #{$urlId} не найден у бренда.");
        return $this->redirectToRoute('admin_rag_brand_panel', ['id' => $id]);
    }

    /** Soft-delete нерелевантного документа: deleted_at + удаление его чанков из Qdrant + регенерация. */
    #[Route('/brand/{id}/doc/{docId}/remove', name: '_brand_doc_remove', methods: ['POST'], requirements: ['id' => '\d+', 'docId' => '\d+'])]
    public function brandRemoveDoc(int $id, int $docId, Request $request): Response
    {
        $brand = $this->requireBrandCsrf($id, $request);
        if (!$brand instanceof Brand) {
            return $brand;
        }

        $doc = $this->em->getRepository(BrandSourceDocument::class)->findOneBy(['id' => $docId, 'brand' => $brand]);
        if ($doc === null || $doc->getDeletedAt() !== null) {
            $this->addFlash('warning', "Документ #{$docId} не найден или уже удалён.");
            return $this->redirectToRoute('admin_rag_brand_panel', ['id' => $id]);
        }

        $doc->setDeletedAt(new \DateTime());

        // Чистим чанки документа из Qdrant (fail-soft: сервер мог быть недоступен).
        try {
            $this->vectors->deleteByDoc($id, $docId);
        } catch (\Throwable $e) {
            $this->addFlash('warning', 'Qdrant недоступен — чанки не удалены, повторите «↻ embed» позже: ' . $e->getMessage());
        }

        // Перегенерируем описание из оставшихся чанков.
        $p = $this->pipelineFor($brand);
        $p->setStatus(BrandRagPipeline::STATUS_EMBEDDED)
          ->setGeneratedAt(null)
          ->setContentChangedAt(new \DateTime());

        $this->em->flush();

        $this->addFlash('success', "Документ #{$docId} убран (soft-delete) + чанки удалены. Бренд поставлен на регенерацию.");
        return $this->redirectToRoute('admin_rag_brand_panel', ['id' => $id]);
    }

    /** Проверка CSRF + загрузка Brand-сущности. Возвращает Brand или redirect-Response. */
    private function requireBrandCsrf(int $id, Request $request): Brand|Response
    {
        if (!$this->isCsrfTokenValid('rag_brand_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('admin_rag_brand_panel', ['id' => $id]);
        }
        $brand = $this->em->getRepository(Brand::class)->find($id);
        if ($brand === null) {
            $this->addFlash('danger', "Бренд #{$id} не найден.");
            return $this->redirectToRoute('admin_rag');
        }
        return $brand;
    }

    /** Находит строку пайплайна бренда или создаёт новую (статус pending). */
    private function pipelineFor(Brand $brand): BrandRagPipeline
    {
        $p = $this->em->getRepository(BrandRagPipeline::class)->findOneBy(['brand' => $brand]);
        if ($p === null) {
            $p = (new BrandRagPipeline())->setBrand($brand);
            $this->em->persist($p);
        }
        return $p;
    }

    /**
     * Эти страницы — обычные #[Route]-контроллеры, а не EasyAdmin-CRUD, поэтому EA не
     * создаёт AdminContext автоматически → Twig-функция ea() вернула бы null и layout
     * упал бы на ea.i18n. Строим контекст вручную (как EA для лендинга дашборда:
     * dashboard-контроллер + null CRUD) и кладём в атрибут запроса, откуда его читает
     * AdminContextProvider.
     */
    private function initAdminContext(Request $request): void
    {
        if ($request->attributes->has(EA::CONTEXT_REQUEST_ATTRIBUTE)) {
            return;
        }
        $context = $this->adminContextFactory->create($request, $this->dashboard, null);
        $request->attributes->set(EA::CONTEXT_REQUEST_ATTRIBUTE, $context);
    }

    private function tierForType(string $type): int
    {
        return match ($type) {
            BrandSourceUrl::TYPE_OWN_SITE => BrandSourceUrl::TIER_OWN_SITE,
            BrandSourceUrl::TYPE_SOCIAL, BrandSourceUrl::TYPE_MENTION => BrandSourceUrl::TIER_MENTIONS,
            default => BrandSourceUrl::TIER_CORPUS,
        };
    }

    /**
     * GSC-статистика (gsc_index_status / gsc_page_stats). Эти таблицы наполняются
     * только на проде; на dev их может не быть → fail-soft (иначе вся страница 500).
     *
     * @return array<string,mixed>
     */
    private function gscStats(): array
    {
        try {
            $one = fn(string $sql) => (int) $this->db->fetchOne($sql);
            $cohort = $this->db->fetchAssociative(
                "SELECT COUNT(s.id) checked, COALESCE(SUM(s.indexed),0) idx FROM brand b
                 JOIN gsc_index_status s ON s.brand_id = b.id
                 WHERE b.published_at IS NOT NULL AND b.published_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)",
            ) ?: ['checked' => 0, 'idx' => 0];

            return [
                'проверено страниц' => $one("SELECT COUNT(*) FROM gsc_index_status"),
                'в индексе Google'  => $one("SELECT COALESCE(SUM(indexed),0) FROM gsc_index_status"),
                'когорта 14д+ в индексе' => (int) $cohort['checked'] > 0
                    ? sprintf('%d/%d (%.0f%%)', $cohort['idx'], $cohort['checked'], 100 * $cohort['idx'] / $cohort['checked'])
                    : '— (нет когорты)',
                'последняя проверка' => $this->db->fetchOne("SELECT MAX(last_checked_at) FROM gsc_index_status") ?: '—',
                'строк аналитики'   => $one("SELECT COUNT(*) FROM gsc_page_stats"),
            ];
        } catch (\Throwable) {
            return ['GSC' => 'таблицы недоступны (gsc_* наполняются на проде)'];
        }
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
