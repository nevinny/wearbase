<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Wardrobe;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeOutfit;
use App\Repository\WardrobeConsentRepository;
use App\Repository\WardrobeItemRepository;
use App\Repository\WardrobeOutfitRepository;
use App\Repository\WardrobeRepository;
use App\Service\Wardrobe\WardrobeOutfitCollageRenderer;
use App\Service\Wardrobe\PreparedWardrobePhoto;
use App\Service\Wardrobe\WardrobeOutfitLearningService;
use App\Service\Wardrobe\WardrobeStylistContextBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Агент-API (прод) для ночного пакетного конвейера образов «на утро»
 * (app:wardrobe:daily-outfits на Mac): прод физически не достаёт до домашнего
 * GPU-рига, поэтому направление одно — Mac инициирует оба конца, прод пассивно
 * отдаёт каталог и принимает результат.
 *
 * Auth (тот же паттерн, что у BrandIngestController::authorize(), но без
 * HMAC-подписи тела — только X-Agent-Token, см. константу apiToken):
 *  - X-Agent-Token: <AGENT_API_TOKEN>  (hash_equals; Authorization — fallback,
 *    т.к. nginx/Apache срезают этот заголовок без спец-конфига)
 * Rate limit: framework.rate_limiter.agent_api (429 при превышении).
 *
 * Согласие: интерактивная local-подсказка тоже персистится — WardrobeOutfitService::suggest()
 * сам ничего не сохраняет, но вызывающий WardrobeOutfitController::__invoke() сразу зовёт
 * WardrobeOutfitLearningService::remember(). Разница этого конвейера не в факте сохранения,
 * а в том, что он ПЕРСИСТИРУЕТ WardrobeOutfit для владельца, который сам ничего не запрашивал
 * (инициатор — ночной крон, не пользователь). Поэтому и catalog(), и outfits() пропускают
 * гардеробы без WardrobeConsentRepository::isPersonalizationGranted() у владельца.
 */
#[Route('/api/v1/wardrobe/daily')]
class WardrobeDailyController extends AbstractController
{
    private const MIN_ITEMS_FOR_OUTFIT = 2;
    private const MAX_OUTFITS_PER_REQUEST = 6;
    // Потолок отдачи очереди подготовки атрибутов — внутренний предохранитель проды
    // (Mac фильтрует и режет по своему --limit уже после получения списка, как
    // --wardrobe у app:wardrobe:daily-outfits), не query-параметр клиента.
    private const MAX_PREPARE_QUEUE = 200;
    private const MAX_PREPARE_RESULTS = 200;
    private const SEASON_ALLOWLIST = ['all', 'spring', 'summer', 'autumn', 'winter'];

    public function __construct(
        #[Autowire('%env(default::AGENT_API_TOKEN)%')]
        private readonly ?string $apiToken,
        #[Autowire('%env(default::AGENT_API_SECRET)%')]
        private readonly ?string $apiSecret,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/catalog', name: 'api_wardrobe_daily_catalog', methods: ['GET'])]
    public function catalog(
        Request $request,
        RateLimiterFactory $agentApiLimiter,
        WardrobeRepository $wardrobes,
        WardrobeItemRepository $items,
        WardrobeConsentRepository $consents,
        WardrobeStylistContextBuilder $contextBuilder,
        WardrobeOutfitLearningService $learning,
    ): JsonResponse {
        if (($deny = $this->authorize($request, $agentApiLimiter)) !== null) {
            return $deny;
        }

        $result = [];
        foreach ($wardrobes->findActiveDefaults() as $wardrobe) {
            $owner = $wardrobe->getOwner();
            // Пакетный конвейер хранит результат в БД без действия владельца (в отличие
            // от эфемерной интерактивной local-подсказки) — без согласия не тратим GPU.
            if ($owner === null || !$consents->isPersonalizationGranted($owner)) {
                continue;
            }
            // Те же фильтры (ITEM_ACTIVE + WEAR_ACTIVE + CLEANLINESS_CLEAN) и та же ротация
            // fresh/recent, что у интерактивного стилиста — билдер, а не своя выборка,
            // иначе ночной батч предложит грязные вещи и вещи в стирке.
            $context = $contextBuilder->build($owner, $items->findActiveForUser($owner), null);
            $activeItems = $context['items'];
            if (count($activeItems) < self::MIN_ITEMS_FOR_OUTFIT) {
                continue;
            }
            $result[] = [
                'wardrobe_id' => $wardrobe->getId(),
                'owner_id' => $owner->getId(),
                'items' => array_map(fn (WardrobeItem $item): array => $this->itemRow($item, $context['rotation']), $activeItems),
                // Та же история реакций/носки, что видит интерактивный стилист (WardrobeOutfitController).
                'preference_context' => $learning->context($owner),
            ];
        }

        return $this->json(['wardrobes' => $result]);
    }

    /**
     * @param array<int,string> $rotation item id => 'fresh'|'recent' (см. WardrobeStylistContextBuilder)
     * @return array{id:int,category:?string,colorName:?string,season:?string,materialText:?string,name:?string,styles:string[],rotation:string}
     */
    private function itemRow(WardrobeItem $item, array $rotation): array
    {
        return [
            'id' => (int) $item->getId(),
            'category' => $item->getCategory(),
            'colorName' => $item->getColorName(),
            'season' => $item->getSeason(),
            'materialText' => $item->getMaterialText(),
            'name' => $item->getName(),
            'styles' => array_map(static fn ($style): string => $style->getTitle(), $item->getStyles()->toArray()),
            'rotation' => $rotation[(int) $item->getId()] ?? 'fresh',
        ];
    }

    #[Route('/images/queue', name: 'api_wardrobe_daily_images_queue', methods: ['GET'])]
    public function imageQueue(
        Request $request,
        RateLimiterFactory $agentApiLimiter,
        WardrobeItemRepository $items,
        WardrobeConsentRepository $consents,
    ): JsonResponse {
        if (($deny = $this->authorize($request, $agentApiLimiter)) !== null) {
            return $deny;
        }

        $after = max(0, $request->query->getInt('after'));
        $candidates = $items->findImageCandidates($after);
        $queue = [];
        foreach ($candidates as $item) {
            $owner = $item->getUser();
            $consent = $owner === null ? null : $consents->findForSubject($owner);
            $path = PreparedWardrobePhoto::path($this->projectDir, $item);
            if ($path === null || is_file($path) || !$consent?->isPhotoProcessingGranted()
                || !$consent->isPersonalizationGranted()) {
                continue;
            }
            $queue[] = ['id' => $item->getId(), 'revision' => PreparedWardrobePhoto::revision($item)];
        }

        return $this->json([
            'items' => $queue,
            'next_after' => $candidates === [] ? null : end($candidates)->getId(),
        ]);
    }

    #[Route('/images/result/{id}', name: 'api_wardrobe_daily_images_result', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function imageResult(
        int $id,
        Request $request,
        RateLimiterFactory $agentApiLimiter,
        WardrobeItemRepository $items,
        WardrobeConsentRepository $consents,
    ): JsonResponse {
        if (($deny = $this->authorize($request, $agentApiLimiter)) !== null) {
            return $deny;
        }
        $bytes = $request->getContent();
        if ($this->apiSecret === null || $this->apiSecret === '' || !hash_equals(
            hash_hmac('sha256', $bytes, $this->apiSecret),
            (string) $request->headers->get('X-Signature'),
        )) {
            return $this->json(['error' => 'bad signature'], Response::HTTP_UNAUTHORIZED);
        }
        $item = $items->find($id);
        $owner = $item?->getUser();
        $consent = $owner === null ? null : $consents->findForSubject($owner);
        $path = $item === null ? null : PreparedWardrobePhoto::path($this->projectDir, $item);
        if ($item?->getDeletedAt() !== null || $path === null || !$consent?->isPhotoProcessingGranted()
            || !$consent->isPersonalizationGranted()) {
            return $this->json(['error' => 'item unavailable'], Response::HTTP_NOT_FOUND);
        }
        if (!hash_equals((string) PreparedWardrobePhoto::revision($item), (string) $request->headers->get('X-Source-Revision'))) {
            return $this->json(['error' => 'source changed'], Response::HTTP_CONFLICT);
        }
        $dimensions = strlen($bytes) <= 10_000_000 ? @getimagesizefromstring($bytes) : false;
        if ($dimensions === false || $dimensions['mime'] !== 'image/png'
            || $dimensions[0] > 5000 || $dimensions[1] > 5000) {
            return $this->json(['error' => 'invalid PNG'], Response::HTTP_BAD_REQUEST);
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return $this->json(['error' => 'storage unavailable'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        $temporary = tempnam($directory, 'prepared-');
        if ($temporary === false || file_put_contents($temporary, $bytes) === false || !rename($temporary, $path)) {
            if ($temporary !== false && is_file($temporary)) {
                unlink($temporary);
            }
            return $this->json(['error' => 'storage unavailable'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['status' => 'saved']);
    }

    /**
     * Тело (application/json): {"wardrobe_id":N,"occasion":"work","request":"...",
     * "outfits":[{"title":"...","explanation":"...","item_ids":[1,2]}]}.
     *
     * Главная защита от галлюцинации модели: образ отбрасывается целиком, если хоть
     * одна вещь не найдена среди активных вещей ЭТОГО гардероба (чужая или удалённая).
     * Идемпотентность: перед вставкой принятых образов гасятся (soft-delete) прежние
     * образы того же гардероба+повода за сегодня — повторный прогон не плодит дубли.
     *
     * Согласие: без WardrobeConsentRepository::isPersonalizationGranted() у владельца
     * гардероба ничего не сохраняем — 200 {"status":"consent_denied"}, не 4xx (это одно
     * из многих обращений в рамках ночного прогона, остальные гардеробы не должны падать).
     */
    #[Route('/outfits', name: 'api_wardrobe_daily_outfits', methods: ['POST'])]
    public function outfits(
        Request $request,
        RateLimiterFactory $agentApiLimiter,
        WardrobeRepository $wardrobes,
        WardrobeItemRepository $items,
        WardrobeOutfitRepository $outfitRepo,
        WardrobeConsentRepository $consents,
        EntityManagerInterface $em,
        WardrobeOutfitCollageRenderer $collageRenderer,
        LoggerInterface $logger,
    ): JsonResponse {
        if (($deny = $this->authorize($request, $agentApiLimiter)) !== null) {
            return $deny;
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'invalid json'], Response::HTTP_BAD_REQUEST);
        }

        $wardrobeId = filter_var($payload['wardrobe_id'] ?? null, FILTER_VALIDATE_INT);
        $occasion = (string) ($payload['occasion'] ?? '');
        $outfitsPayload = is_array($payload['outfits'] ?? null) ? $payload['outfits'] : [];

        if ($wardrobeId === false || !array_key_exists($occasion, WardrobeOutfit::DAILY_OCCASIONS)) {
            return $this->json(['error' => 'invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        $wardrobe = $wardrobes->find($wardrobeId);
        if (!$wardrobe instanceof Wardrobe || $wardrobe->getDeletedAt() !== null || $wardrobe->getStatus() !== Wardrobe::STATUS_ACTIVE) {
            return $this->json(['error' => 'wardrobe_not_found'], Response::HTTP_NOT_FOUND);
        }
        $owner = $wardrobe->getOwner();
        if ($owner === null) {
            return $this->json(['error' => 'wardrobe_not_found'], Response::HTTP_NOT_FOUND);
        }
        if (!$consents->isPersonalizationGranted($owner)) {
            return $this->json(['status' => 'consent_denied', 'wardrobe_id' => $wardrobeId, 'created' => 0, 'rejected' => []]);
        }

        $validItems = [];
        foreach ($items->findActiveForUser($owner) as $item) {
            $validItems[(int) $item->getId()] = $item;
        }

        $requestText = mb_substr(trim((string) ($payload['request'] ?? '')), 0, 300) ?: WardrobeOutfit::DAILY_OCCASIONS[$occasion];

        $accepted = [];
        $rejected = [];
        foreach (array_slice($outfitsPayload, 0, self::MAX_OUTFITS_PER_REQUEST) as $index => $outfit) {
            if (!is_array($outfit)) {
                $rejected[] = ['index' => $index, 'reason' => 'invalid_outfit'];
                continue;
            }
            $ids = is_array($outfit['item_ids'] ?? null) ? $outfit['item_ids'] : [];
            $resolved = [];
            $unknown = false;
            foreach ($ids as $id) {
                $id = filter_var($id, FILTER_VALIDATE_INT);
                if ($id !== false && isset($validItems[$id])) {
                    $resolved[$id] = $validItems[$id];
                } else {
                    $unknown = true;
                }
            }
            if ($unknown) {
                $rejected[] = ['index' => $index, 'reason' => 'unknown_item_id', 'item_ids' => $ids];
                continue;
            }
            if (count($resolved) < self::MIN_ITEMS_FOR_OUTFIT) {
                $rejected[] = ['index' => $index, 'reason' => 'too_few_items', 'item_ids' => $ids];
                continue;
            }
            $accepted[] = [
                'title' => mb_substr(trim((string) ($outfit['title'] ?? 'Образ дня')), 0, 100),
                'explanation' => mb_substr(trim((string) ($outfit['explanation'] ?? '')), 0, 240),
                'items' => array_values($resolved),
            ];
        }

        if ($accepted !== []) {
            $todayStart = new \DateTimeImmutable('today');
            $outfitRepo->softDeleteDailyBatch($owner, $occasion, $todayStart, $todayStart->modify('+1 day'));

            $created = [];
            foreach ($accepted as $outfit) {
                $entity = (new WardrobeOutfit())
                    ->setUser($owner)
                    ->setWardrobeOwner($owner)
                    ->setOccasion($occasion)
                    ->setPrompt($requestText)
                    ->setTitle($outfit['title'])
                    ->setExplanation($outfit['explanation'])
                    ->setItems(array_map($this->itemSnapshot(...), $outfit['items']));
                $em->persist($entity);
                $created[] = [$entity, $outfit['items']];
            }
            $em->flush();

            // Коллаж — производная от уже сохранённого образа: собирается здесь же, ОДИН раз за
            // ночной батч (не на лету при открытии /account/wardrobe/outfits, см. докблок
            // рендерера). Сбой рендера (битое фото, GD-ошибка) не должен ронять ответ агент-API —
            // WardrobeOutfit к этому моменту уже во флаше, коллаж — необязательная надстройка.
            foreach ($created as [$entity, $items]) {
                try {
                    $collageRenderer->render($entity, $items);
                } catch (\Throwable $e) {
                    // Карточка образа деградирует к списку вещей (см. twig) — но лог обязателен,
                    // иначе «коллажи молча перестали собираться» не увидит никто.
                    $logger->warning('Не удалось собрать коллаж образа', [
                        'outfit_id' => $entity->getId(),
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $this->json([
            'status' => 'ok',
            'wardrobe_id' => $wardrobeId,
            'created' => count($accepted),
            'rejected' => $rejected,
        ]);
    }

    /**
     * Очередь вещей без AI-атрибутов для домашнего распознавания на Mac
     * (app:wardrobe:prepare-prod-items): прод не достаёт до GPU-рига (см.
     * класс-докблок), поэтому Mac инициирует и здесь. Тот же критерий, что у
     * WardrobeItemRepository::findNeedingPreparation(), но без ограничения по
     * владельцу — отдаём разом по всей платформе. Сам репозиторий не трогаем
     * (см. ограничение задачи), строим тот же WHERE через его унаследованный
     * public createQueryBuilder().
     *
     * Фильтрация по гардеробу/владельцу — на стороне Mac (client-side, как
     * --wardrobe у app:wardrobe:daily-outfits): здесь просто отдаём всё до
     * внутреннего потолка MAX_PREPARE_QUEUE, никаких query-параметров.
     */
    #[Route('/prepare/queue', name: 'api_wardrobe_daily_prepare_queue', methods: ['GET'])]
    public function prepareQueue(
        Request $request,
        RateLimiterFactory $agentApiLimiter,
        WardrobeItemRepository $items,
    ): JsonResponse {
        if (($deny = $this->authorize($request, $agentApiLimiter)) !== null) {
            return $deny;
        }

        $rows = $items->createQueryBuilder('w')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere('w.itemStatus = :active AND w.wearStatus = :wear')
            ->andWhere("(w.category IS NULL OR TRIM(w.category) = '' OR w.colorName IS NULL OR TRIM(w.colorName) = '' OR w.season IS NULL OR TRIM(w.season) = '')")
            ->setParameter('active', WardrobeItem::ITEM_ACTIVE)
            ->setParameter('wear', WardrobeItem::WEAR_ACTIVE)
            ->orderBy('w.id', 'ASC')
            ->setMaxResults(self::MAX_PREPARE_QUEUE)
            ->getQuery()
            ->getResult();

        return $this->json(['items' => array_map(fn (WardrobeItem $item): array => [
            'id' => (int) $item->getId(),
            'wardrobe_id' => $item->getWardrobe()?->getId(),
            'owner_email' => $item->getUser()?->getEmail(),
            // Приоритет источников на Mac: WB-карточка (product_url) → фото → название.
            // has_photo — есть ХОТЬ КАКОЙ-ТО снимок (обложка галереи ИЛИ legacy-поле photo);
            // без этого признака до починки резолва в preparePhoto() вещи с фото только в
            // галерее (см. WardrobeItemPhoto) отдавали 404 и никогда не распознавались.
            'has_photo' => $item->getCoverPhoto() !== null || !$this->isEmpty($item->getPhoto()),
            'name' => $item->getName(),
            'category' => $item->getCategory(),
            'material_text' => $item->getMaterialText(),
            'product_url' => $item->getProductUrl(),
        ], $rows)]);
    }

    /**
     * Байты фото вещи для локального распознавания на Mac. Отдельный эндпоинт
     * (а не base64 внутри prepareQueue()): один прод-ответ разом со всей пачкой
     * фото легко перевалит за память шаред-хостинга (см. CLAUDE.md про
     * memory_limit) и раздует JSON на ~33% (base64). BinaryFileResponse стримит
     * файл с диска без буферизации в PHP — тот же приём, что
     * WardrobeMediaController, но авторизация здесь по агент-токену, а не по
     * сессии: агент не залогинен, вещь может принадлежать любому пользователю
     * платформы, и публичный /account/wardrobe/media/item/{id} тут не подходит
     * (собственнический доступ через FamilyService там не про агента).
     */
    #[Route('/prepare/photo/{id}', name: 'api_wardrobe_daily_prepare_photo', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function preparePhoto(
        int $id,
        Request $request,
        RateLimiterFactory $agentApiLimiter,
        WardrobeItemRepository $items,
        StorageInterface $storage,
    ): Response {
        if (($deny = $this->authorize($request, $agentApiLimiter)) !== null) {
            return $deny;
        }

        $item = $items->find($id);
        if (!$item instanceof WardrobeItem || $item->getDeletedAt() !== null) {
            return $this->json(['error' => 'item_not_found'], Response::HTTP_NOT_FOUND);
        }

        // Тот же порядок и тот же legacy-фолбэк, что в карточке ЛК и
        // WardrobeMediaController::mediaResponse() (шаблоны: coverPhoto → photo, см.
        // account/wardrobe/show.html.twig): часть импортированных фото ещё физически
        // лежит в public_html/images/wardrobe (app:wardrobe:migrate-private-media их не
        // трогало — WardrobeRestoreBackupCommand пишет filePath, но не копирует байты в
        // var/uploads/wardrobe), иначе Vich resolvePath() их не находит и отдаёт 404.
        $cover = $item->getCoverPhoto();
        $path = $cover !== null
            ? $this->resolveMediaPath($storage->resolvePath($cover, 'file'), $cover->getFilePath())
            : null;
        if ($path === null) {
            $path = $this->resolveMediaPath($storage->resolvePath($item, 'photoFile'), $item->getPhoto());
        }
        if ($path === null) {
            return $this->json(['error' => 'photo_not_found'], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($path);
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * Тело: {"items":[{"id":N,"category":?,"colorName":?,"materialText":?,"season":?,
     * "countryOfOrigin":?,"careText":?}]} — последние два приходят из WB-карточки
     * (см. WildberriesAdapter::fetchCard()), остальные — из WB/фото/названия.
     *
     * Критично: заполняет ТОЛЬКО пустые поля вещи — ровно то же правило, что у
     * PrepareExistingItemsCommand (см. её метод execute(), блок с hasValue()):
     * значение, которое уже ввёл человек, автоматика никогда не перезаписывает.
     * season защищён тем же аллоулистом, что и WardrobeAiService::normalizePhotoFields
     * (неопознанное значение отбрасывается) — не доверяем сетевому payload вслепую,
     * даже от собственного агента.
     */
    #[Route('/prepare/results', name: 'api_wardrobe_daily_prepare_results', methods: ['POST'])]
    public function prepareResults(
        Request $request,
        RateLimiterFactory $agentApiLimiter,
        WardrobeItemRepository $items,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (($deny = $this->authorize($request, $agentApiLimiter)) !== null) {
            return $deny;
        }

        $payload = json_decode((string) $request->getContent(), true);
        $rows = is_array($payload) && is_array($payload['items'] ?? null) ? $payload['items'] : null;
        if ($rows === null) {
            return $this->json(['error' => 'invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        $updated = 0;
        $skipped = 0;
        $rejected = [];
        foreach (array_slice($rows, 0, self::MAX_PREPARE_RESULTS) as $row) {
            $id = is_array($row) ? filter_var($row['id'] ?? null, FILTER_VALIDATE_INT) : false;
            $item = $id !== false ? $items->find($id) : null;
            if (!$item instanceof WardrobeItem || $item->getDeletedAt() !== null) {
                $rejected[] = ['id' => is_array($row) ? ($row['id'] ?? null) : null, 'reason' => 'not_found'];
                continue;
            }

            $changed = false;
            if ($this->isEmpty($item->getCategory()) && $this->isNonEmptyString($row['category'] ?? null)) {
                $item->setCategory(mb_substr(trim((string) $row['category']), 0, 100));
                $changed = true;
            }
            if ($this->isEmpty($item->getColorName()) && $this->isNonEmptyString($row['colorName'] ?? null)) {
                $item->setColorName(mb_substr(trim((string) $row['colorName']), 0, 100));
                $changed = true;
            }
            if ($this->isEmpty($item->getMaterialText()) && $this->isNonEmptyString($row['materialText'] ?? null)) {
                $item->setMaterialText(mb_substr(trim((string) $row['materialText']), 0, 2000));
                $changed = true;
            }
            if ($this->isEmpty($item->getSeason()) && in_array($row['season'] ?? null, self::SEASON_ALLOWLIST, true)) {
                $item->setSeason($row['season']);
                $changed = true;
            }
            if ($this->isEmpty($item->getCountryOfOrigin()) && $this->isNonEmptyString($row['countryOfOrigin'] ?? null)) {
                $item->setCountryOfOrigin(mb_substr(trim((string) $row['countryOfOrigin']), 0, 100));
                $changed = true;
            }
            if ($this->isEmpty($item->getCareText()) && $this->isNonEmptyString($row['careText'] ?? null)) {
                $item->setCareText(mb_substr(trim((string) $row['careText']), 0, 2000));
                $changed = true;
            }

            if ($changed) {
                $updated++;
            } else {
                $skipped++;
            }
        }

        if ($updated > 0) {
            $em->flush();
        }

        return $this->json(['status' => 'ok', 'updated' => $updated, 'skipped' => $skipped, 'rejected' => $rejected]);
    }

    /**
     * Verbatim-мирроринг legacy-фолбэка WardrobeMediaController::mediaResponse() —
     * см. комментарий в preparePhoto(). $legacyName — сырое имя файла (без каталогов;
     * aa/bb даёт SubdirDirectoryNamer детерминированно, в БД не хранится).
     */
    private function resolveMediaPath(?string $vichPath, ?string $legacyName): ?string
    {
        if ($vichPath !== null && is_file($vichPath)) {
            return $vichPath;
        }
        if ($legacyName === null || basename($legacyName) !== $legacyName) {
            return null;
        }
        $root = realpath($this->projectDir.'/public_html/images/wardrobe');
        if ($root === false) {
            return null;
        }
        foreach ([$legacyName, mb_substr($legacyName, 0, 2).'/'.mb_substr($legacyName, 2, 2).'/'.$legacyName] as $relativePath) {
            $legacyPath = realpath($root.'/'.$relativePath);
            if ($legacyPath !== false && str_starts_with($legacyPath, $root.DIRECTORY_SEPARATOR)) {
                return $legacyPath;
            }
        }

        return null;
    }

    private function isEmpty(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /** @return array{id:int,category:?string,color:?string,styles:string[]} */
    private function itemSnapshot(WardrobeItem $item): array
    {
        return [
            'id' => (int) $item->getId(),
            'category' => $item->getCategory(),
            'color' => $item->getColorName(),
            'styles' => array_map(static fn ($style): string => $style->getTitle(), $item->getStyles()->toArray()),
        ];
    }

    /** 401/403/429 либо null (доступ разрешён). Без HMAC-подписи — см. класс-докблок. */
    private function authorize(Request $request, RateLimiterFactory $limiter): ?JsonResponse
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

        return null;
    }
}
