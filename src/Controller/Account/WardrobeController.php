<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\AiUsageLog;
use App\Entity\BrandStyle;
use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemPhoto;
use App\Entity\WardrobeTransfer;
use App\Form\Account\WardrobeItemFormType;
use App\Repository\WardrobeItemRepository;
use App\Repository\WardrobeConsentRepository;
use App\Repository\WardrobeTransferRepository;
use App\Repository\WardrobeItemLifecycleEventRepository;
use App\Service\AiUsageTracker;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeAiService;
use App\Service\Wardrobe\WardrobeActivationService;
use App\Service\Wardrobe\WardrobeManager;
use App\Service\Wardrobe\WardrobePhotoManager;
use App\Service\Wardrobe\WardrobeRemotePhotoFetcher;
use App\Service\Wardrobe\WardrobeStatisticsService;
use App\Service\Wardrobe\WardrobeImageSanitizer;
use App\Service\Wardrobe\WardrobeConsentService;
use App\Service\Wardrobe\WardrobeWearService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Vich\UploaderBundle\Storage\StorageInterface;

#[Route('/account/wardrobe', name: 'account_wardrobe_')]
class WardrobeController extends AbstractController
{
    public function __construct(
        private readonly FamilyService $familyService,
        private readonly WardrobeManager $wardrobeManager,
        private readonly WardrobePhotoManager $photoManager,
        private readonly WardrobeRemotePhotoFetcher $remotePhotoFetcher,
        private readonly WardrobeImageSanitizer $imageSanitizer,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, WardrobeItemRepository $repo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $currentMember = $this->familyService->resolveMember($user, $this->memberParam($request));

        $isArchiveView = $request->query->get('view') === 'archive';
        $filters = $this->wardrobeFilters($request);
        // Sold/donated/lost/archived существуют только в архивном срезе (см.
        // WardrobeItem::ARCHIVE_STATUSES) — вне архива такой status сбрасываем,
        // иначе плитка статистики «Продана» вела бы в пустой список.
        if ($isArchiveView) {
            $filters['wear'] = '';
            if (!in_array($filters['status'], WardrobeItem::ARCHIVE_STATUSES, true)) {
                $filters['status'] = '';
            }
        } elseif (in_array($filters['status'], WardrobeItem::ARCHIVE_STATUSES, true)) {
            $filters['status'] = '';
        }
        $activeFilters = array_filter($filters, static fn (string $value): bool => $value !== '');
        $hasFilters = count($activeFilters) > 0;
        $items = $repo->searchForUser($currentMember, $filters, $isArchiveView);
        $stats = (!$isArchiveView && !$hasFilters) ? $repo->getStats($currentMember) : [];
        $filteredSum = array_sum(array_map(static fn (WardrobeItem $item): float => (float) ($item->getPrice() ?? 0), $items));

        return $this->render('account/wardrobe/index.html.twig', [
            'items'         => $items,
            'stats'         => $stats,
            'totalCount'    => $hasFilters || $isArchiveView ? count($items) : (int) array_sum(array_column($stats, 'cnt')),
            'totalSum'      => $hasFilters || $isArchiveView ? $filteredSum : array_sum(array_map('floatval', array_column($stats, 'total'))),
            'members'       => $this->familyService->membersFor($user),
            'currentMember' => $currentMember,
            'isOwnWardrobe' => $currentMember->getId() === $user->getId(),
            'canExportFamily' => $user->isFamilyParent() && $user->getFamily() !== null,
            'isArchiveView' => $isArchiveView,
            'filters' => $filters,
            // Только непустые значения — иначе ссылки/пагинация тащат q=&category=&...
            'activeFilters' => $activeFilters,
            'hasFilters' => $hasFilters,
            'filterOptions' => $repo->getFilterOptions($currentMember, $isArchiveView),
        ]);
    }

    #[Route('/statistics', name: 'statistics', methods: ['GET'])]
    public function statistics(Request $request, WardrobeStatisticsService $statistics): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $currentMember = $this->familyService->resolveMember($user, $this->memberParam($request));
        $members = $this->familyService->membersFor($user);
        $currentStatistics = $statistics->forUser($currentMember);

        // Та же авторизация, что и у resolveMember() (canManage): не-parent видит
        // сравнение только по себе — сумма гардероба и % заполнения остальных не палим.
        $familyComparison = [];
        foreach ($members as $member) {
            if (!$this->familyService->canManage($user, $member)) {
                continue;
            }
            $summary = $member->getId() === $currentMember->getId()
                ? $currentStatistics['summary']
                : $statistics->summaryForUser($member);
            $familyComparison[] = ['member' => $member, 'summary' => $summary];
        }

        return $this->render('account/wardrobe/statistics.html.twig', [
            'statistics' => $currentStatistics,
            'members' => $members,
            'currentMember' => $currentMember,
            'isOwnWardrobe' => $currentMember->getId() === $user->getId(),
            'familyComparison' => $familyComparison,
        ]);
    }

    #[Route('/{id}/photos', name: 'photos_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadPhotos(
        int $id,
        Request $request,
        WardrobeItemRepository $repo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $currentMember = $this->familyService->resolveMember($user, $this->memberParam($request));
        $item = $repo->findActiveOneForUser($id, $currentMember);
        if (!$item) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('wardrobe_photos_'.$id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
        } else {
            try {
                $files = $request->files->all('photos');
                $this->photoManager->upload($item, is_array($files) ? $files : [], (string) $request->request->get('photo_type', 'product'));
                $this->wardrobeManager->refreshCompletionStatus($item);
                $this->addFlash('success', 'Фотографии добавлены');
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }
        return $this->redirectToRoute('account_wardrobe_show', ['id' => $id] + $this->memberQuery($user, $currentMember));
    }

    #[Route('/{id}/photos/{photoId}/cover', name: 'photo_cover', requirements: ['id' => '\d+', 'photoId' => '\d+'], methods: ['POST'])]
    public function setPhotoCover(
        int $id,
        int $photoId,
        Request $request,
        WardrobeItemRepository $repo,
        EntityManagerInterface $em,
    ): Response {
        [$user, $currentMember, $item, $photo] = $this->resolvePhotoAction($id, $photoId, $request, $repo, $em);
        if (!$this->isCsrfTokenValid('wardrobe_photo_'.$photoId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
        } else {
            try {
                $this->photoManager->setCover($item, $photo);
                $this->addFlash('success', 'Обложка обновлена');
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }
        return $this->redirectToRoute('account_wardrobe_show', ['id' => $id] + $this->memberQuery($user, $currentMember));
    }

    #[Route('/{id}/photos/{photoId}/delete', name: 'photo_delete', requirements: ['id' => '\d+', 'photoId' => '\d+'], methods: ['POST'])]
    public function deletePhoto(
        int $id,
        int $photoId,
        Request $request,
        WardrobeItemRepository $repo,
        EntityManagerInterface $em,
    ): Response {
        [$user, $currentMember, $item, $photo] = $this->resolvePhotoAction($id, $photoId, $request, $repo, $em);
        if (!$this->isCsrfTokenValid('wardrobe_photo_'.$photoId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
        } else {
            try {
                $this->photoManager->softDelete($item, $photo);
                $this->wardrobeManager->refreshCompletionStatus($item);
                $this->addFlash('success', 'Фотография убрана');
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }
        return $this->redirectToRoute('account_wardrobe_show', ['id' => $id] + $this->memberQuery($user, $currentMember));
    }

    #[Route('/{id}/archive', name: 'archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(int $id, Request $request, WardrobeItemRepository $repo): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $currentMember = $this->familyService->resolveMember($user, $this->memberParam($request));
        $item = $repo->findActiveOneForUser($id, $currentMember);
        if (!$item) {
            throw $this->createNotFoundException();
        }
        if ($this->isCsrfTokenValid('archive_wardrobe_item_'.$id, $request->request->get('_token'))) {
            if ($this->wardrobeManager->archive($item)) {
                $this->addFlash('success', 'Вещь перемещена в архив');
            } else {
                $this->addFlash('error', 'Эту вещь нельзя переместить в архив');
            }
        }
        return $this->redirectToRoute('account_wardrobe_index', $this->memberQuery($user, $currentMember));
    }

    #[Route('/{id}/restore', name: 'restore', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function restore(int $id, Request $request, WardrobeItemRepository $repo): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $currentMember = $this->familyService->resolveMember($user, $this->memberParam($request));
        $item = $repo->findActiveOneForUser($id, $currentMember);
        if (!$item) {
            throw $this->createNotFoundException();
        }
        if ($this->isCsrfTokenValid('restore_wardrobe_item_'.$id, $request->request->get('_token'))) {
            if ($this->wardrobeManager->restore($item)) {
                $this->addFlash('success', 'Вещь восстановлена');
            } else {
                $this->addFlash('error', 'Эту вещь нельзя вернуть в гардероб');
            }
        }
        return $this->redirectToRoute('account_wardrobe_index', ['view' => 'archive'] + $this->memberQuery($user, $currentMember));
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        WardrobeItemRepository $repo,
        ManagerRegistry $doctrine,
        WardrobeActivationService $activation,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $currentMember = $this->familyService->resolveMember($user, $this->memberParam($request));

        $item = new WardrobeItem();
        $form = $this->createForm(WardrobeItemFormType::class, $item);
        $form->handleRequest($request);
        $remotePhotoUrl = $form->get('remotePhotoUrl')->getData();
        $galleryPhotos = $form->get('galleryPhotos')->getData() ?? [];

        if ($form->isSubmitted() && $form->isValid()) {
            $this->sanitizeItemPhoto($item);
            if ($item->getPhotoFile() === null && $galleryPhotos === []) {
                $this->remotePhotoFetcher->attachWildberriesPhoto($item, $remotePhotoUrl);
            }
            $item->setUser($currentMember);
            $item->setWardrobe($this->wardrobeManager->getOrCreateDefault($currentMember));
            $item->setOriginalOwner($currentMember);
            $item->setItemNo($repo->nextItemNo($currentMember));
            $item->setSource(WardrobeItem::SOURCE_WEB);
            $this->wardrobeManager->refreshCompletionStatus($item);

            $em = $doctrine->getManager();
            try {
                $em->persist($item);
                $em->flush();
            } catch (UniqueConstraintViolationException) {
                // Гонка за item_no: один retry со свежим номером (EM после исключения закрыт)
                if ($item->getPhoto() === null) {
                    $this->remotePhotoFetcher->discardPendingPhoto($item);
                } else {
                    $item->setPhotoFile(null);
                }
                $doctrine->resetManager();
                $em = $doctrine->getManager();
                /** @var User $currentMember */
                $currentMember = $em->find(User::class, $currentMember->getId());
                $this->wardrobeManager->forgetDefault($currentMember);
                $item->setUser($currentMember);
                $item->setWardrobe($this->wardrobeManager->getOrCreateDefault($currentMember));
                $item->setOriginalOwner($currentMember);
                $item->setItemNo($repo->nextItemNo($currentMember));
                if ($item->getPhoto() === null) {
                    $this->remotePhotoFetcher->attachWildberriesPhoto($item, $remotePhotoUrl);
                }
                $this->wardrobeManager->refreshCompletionStatus($item);
                $em->persist($item);
                $em->flush();
            }

            $activation->firstItemAdded($user, $currentMember, 'manual');

            // Вещь на этот момент уже сохранена, поэтому падение загрузчика нельзя пускать
            // в 500: пользователь увидел бы ошибку и решил, что не сохранилось ничего.
            // Ловим так же, как штатный эндпоинт photos_upload.
            if ($galleryPhotos !== []) {
                try {
                    $this->photoManager->upload($item, $galleryPhotos, WardrobeItemPhoto::TYPE_PRODUCT);
                    $this->wardrobeManager->refreshCompletionStatus($item);
                    $em->flush();
                } catch (\InvalidArgumentException $exception) {
                    $this->addFlash('error', 'Вещь сохранена, но фотографии не загрузились: ' . $exception->getMessage());
                }
            }

            $this->addFlash('success', 'Вещь добавлена');
            if ($request->request->has('save_and_add')) {
                return $this->redirectToRoute('account_wardrobe_new', $this->memberQuery($user, $currentMember));
            }
            return $this->redirectToRoute('account_wardrobe_index', $this->memberQuery($user, $currentMember));
        }

        return $this->render('account/wardrobe/form.html.twig', [
            'form'          => $form,
            'item'          => $item,
            'currentMember' => $currentMember,
            'isOwnWardrobe' => $currentMember->getId() === $user->getId(),
            'fullMode'      => true,
        ]);
    }

    /**
     * Свежий CSRF-токен для AI-запросов. JS берёт его непосредственно перед
     * отправкой: session-based токен, запечённый в HTML, может «осиротеть», если
     * сессия собрана GC (shared-хостинг) к моменту AJAX-вызова → «Недействительный токен».
     */
    #[Route('/ai/token', name: 'ai_token', methods: ['GET'])]
    public function aiToken(CsrfTokenManagerInterface $csrf): JsonResponse
    {
        return $this->json(['token' => $csrf->getToken('wardrobe_ai')->getValue()]);
    }

    /**
     * AI-ассист: распознать параметры вещи по фото (vision LLM). Контракт ответа
     * фиксирован для JS-виджета формы — не менять без синхронизации с фронтом.
     *
     * Вход: multipart `photo` (новая вещь) ИЛИ form-поле `item_id` (перезапрос
     * подсказок по уже сохранённому фото вещи, для карточки/edit-формы).
     */
    #[Route('/ai/photo', name: 'ai_photo', methods: ['POST'])]
    public function aiPhoto(
        Request $request,
        WardrobeAiService $ai,
        RateLimiterFactory $wardrobeAiLimiter,
        WardrobeItemRepository $repo,
        StorageInterface $vichStorage,
        AiUsageTracker $usageTracker,
        LoggerInterface $wardrobeAiLogger,
        ValidatorInterface $validator,
        WardrobeConsentRepository $consents,
        WardrobeConsentService $consentService,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('wardrobe_ai', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'error' => 'Недействительный токен'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();
        if (!$wardrobeAiLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            $usageTracker->recordError($user, AiUsageLog::FEATURE_WARDROBE_PHOTO, 'Лимит AI-подсказок на сегодня');
            $wardrobeAiLogger->error('Лимит AI-подсказок на сегодня', ['feature' => AiUsageLog::FEATURE_WARDROBE_PHOTO, 'user_id' => $user->getId()]);
            return $this->json(['ok' => false, 'error' => 'Лимит AI-подсказок на сегодня'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $photo = $request->files->get('photo');
        if ($photo !== null) {
            if (!$photo instanceof UploadedFile || !$photo->isValid()) {
                return $this->json(['ok' => false, 'error' => 'Файл не получен'], Response::HTTP_BAD_REQUEST);
            }
            if ($error = $this->validateAiPhoto($photo, $validator)) {
                return $error;
            }
            if ($error = $this->photoConsentError($request, $user, $user, $consents, $consentService)) {
                return $error;
            }

            try {
                $sanitized = $this->imageSanitizer->sanitize($photo);
                $result = $ai->suggestFromPhoto($sanitized->getPathname(), $user);
            } catch (\InvalidArgumentException $exception) {
                return $this->json(['ok' => false, 'error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            } finally {
                if (isset($sanitized) && is_file($sanitized->getPathname())) {
                    @unlink($sanitized->getPathname());
                }
            }

            return $this->json($result, $result['ok'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
        }

        $itemId = $request->request->getInt('item_id');
        if ($itemId <= 0) {
            return $this->json(['ok' => false, 'error' => 'Файл не получен'], Response::HTTP_BAD_REQUEST);
        }

        $item = $repo->findActiveOne($itemId);
        if ($item === null || !($item->getUser()->getId() === $user->getId() || $this->familyService->canManage($user, $item->getUser()))) {
            // Не палим существование чужой вещи — та же ошибка, что и «не нашли»
            return $this->json(['ok' => false, 'error' => 'Вещь не найдена'], Response::HTTP_BAD_REQUEST);
        }

        if ($item->getPhoto() === null) {
            return $this->json(['ok' => false, 'error' => 'У вещи нет фото'], Response::HTTP_BAD_REQUEST);
        }

        $absPath = $vichStorage->resolvePath($item, 'photoFile');
        if ($absPath === null || !is_file($absPath)) {
            return $this->json(['ok' => false, 'error' => 'Файл фото не найден'], Response::HTTP_BAD_REQUEST);
        }
        $subject = $item->getUser();
        if ($error = $this->photoConsentError($request, $user, $subject, $consents, $consentService)) {
            return $error;
        }

        $savedPhoto = new UploadedFile($absPath, basename($absPath), (string) mime_content_type($absPath), null, true);
        if ($error = $this->validateAiPhoto($savedPhoto, $validator)) {
            return $error;
        }

        try {
            $sanitized = $this->imageSanitizer->sanitize($savedPhoto);
            $result = $ai->suggestFromPhoto($sanitized->getPathname(), $subject);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['ok' => false, 'error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } finally {
            if (isset($sanitized) && is_file($sanitized->getPathname())) {
                @unlink($sanitized->getPathname());
            }
        }

        return $this->json($result, $result['ok'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    private function validateAiPhoto(UploadedFile $photo, ValidatorInterface $validator): ?JsonResponse
    {
        $violations = $validator->validate($photo, new Image([
            'maxSize' => '10M',
            'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
            'maxWidth' => 5000,
            'maxHeight' => 5000,
        ]));

        return $violations->count() === 0
            ? null
            : $this->json(['ok' => false, 'error' => $violations->get(0)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function photoConsentError(
        Request $request,
        User $actor,
        User $subject,
        WardrobeConsentRepository $consents,
        WardrobeConsentService $consentService,
    ): ?JsonResponse {
        if ($consents->findForSubject($subject)?->isPhotoProcessingGranted()) {
            return null;
        }
        if (!$request->request->getBoolean('photoConsent')) {
            return $this->json(['ok' => false, 'error' => 'Подтвердите согласие на приватную обработку фото'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $consentService->grantPhotoProcessing($actor, $subject);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $exception) {
            return $this->json(['ok' => false, 'error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * AI-ассист: извлечь параметры товара по ссылке (WB — без LLM; иначе scraper+LLM).
     * Контракт ответа фиксирован для JS-виджета формы — не менять без синхронизации с фронтом.
     */
    #[Route('/ai/url', name: 'ai_url', methods: ['POST'])]
    public function aiUrl(
        Request $request,
        WardrobeAiService $ai,
        RateLimiterFactory $wardrobeAiLimiter,
        AiUsageTracker $usageTracker,
        LoggerInterface $wardrobeAiLogger,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('wardrobe_ai', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'error' => 'Недействительный токен'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();
        if (!$wardrobeAiLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            $usageTracker->recordError($user, AiUsageLog::FEATURE_WARDROBE_URL, 'Лимит AI-подсказок на сегодня');
            $wardrobeAiLogger->error('Лимит AI-подсказок на сегодня', ['feature' => AiUsageLog::FEATURE_WARDROBE_URL, 'user_id' => $user->getId()]);
            return $this->json(['ok' => false, 'error' => 'Лимит AI-подсказок на сегодня'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $result = $ai->suggestFromUrl((string) $request->request->get('url'), $user);

        return $this->json($result, $result['ok'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        int $id,
        Request $request,
        WardrobeItemRepository $repo,
        WardrobeTransferRepository $transferRepo,
        WardrobeItemLifecycleEventRepository $lifecycleEvents,
        WardrobeWearService $wear,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $currentMember = $this->familyService->resolveMember($user, $this->memberParam($request));

        $item = $repo->findActiveOneForUser($id, $currentMember);
        if (!$item) {
            throw $this->createNotFoundException();
        }

        // Кандидаты на передачу: семья минус текущий носитель; передаёт только parent
        $transferTargets = [];
        if ($user->isFamilyParent()) {
            $transferTargets = array_values(array_filter(
                $this->familyService->membersFor($user),
                static fn (User $m): bool => $m->getId() !== $currentMember->getId(),
            ));
        }

        return $this->render('account/wardrobe/show.html.twig', [
            'item'            => $item,
            'transfers'       => $transferRepo->findForItem($item),
            'lifecycleEvents' => $lifecycleEvents->findForItem($item),
            'transferTargets' => $transferTargets,
            'canManage'       => $this->familyService->canManage($user, $currentMember),
            'currentMember'   => $currentMember,
            'wearStatistic'   => $wear->statistics($currentMember)[$item->getId()] ?? null,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        WardrobeItemRepository $repo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $currentMember = $this->familyService->resolveMember($user, $this->memberParam($request));

        // Чужая или удалённая вещь → 404 (ownership гарантирует финдер)
        $item = $repo->findActiveOneForUser($id, $currentMember);
        if (!$item) {
            throw $this->createNotFoundException();
        }

        // Форма предлагает к выбору только активные стили (см. WardrobeItemFormType) —
        // уже привязанный, но с тех пор удалённый в админке стиль не попадает в её
        // choice-list, и стандартный add/remove-diff Symfony молча его отвяжет на
        // submit. Запоминаем такие связи до handleRequest и восстанавливаем после.
        $preservedInactiveStyles = array_values(array_filter(
            $item->getStyles()->toArray(),
            static fn (BrandStyle $style): bool => $style->getStatus() !== Statuses::Active,
        ));

        $form = $this->createForm(WardrobeItemFormType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->sanitizeItemPhoto($item);
            foreach ($preservedInactiveStyles as $style) {
                $item->addStyle($style);
            }
            $previousPhoto = $item->getPhoto();
            if ($item->getPhotoFile() === null && $item->getPhoto() === null) {
                $this->remotePhotoFetcher->attachWildberriesPhoto($item, $form->get('remotePhotoUrl')->getData());
            }
            $replacingPhotoFile = $item->getPhotoFile() !== null;
            $this->wardrobeManager->refreshCompletionStatus($item);
            $em->flush();
            if ($replacingPhotoFile) {
                // Vich уже сохранил новый файл и переписал item.photo — согласуем галерею
                // (старое фото не теряем физически, но перестаёт быть обложкой).
                $this->photoManager->reconcileAfterLegacyReplace($item, $previousPhoto);
            }
            // Правки уже во flush выше — ошибка загрузчика не должна их «отменять» в глазах
            // пользователя (см. тот же приём в new() и photos_upload).
            $galleryPhotos = $form->get('galleryPhotos')->getData() ?? [];
            if ($galleryPhotos !== []) {
                try {
                    $this->photoManager->upload($item, $galleryPhotos, WardrobeItemPhoto::TYPE_PRODUCT);
                    $this->wardrobeManager->refreshCompletionStatus($item);
                    $em->flush();
                } catch (\InvalidArgumentException $exception) {
                    $this->addFlash('error', 'Изменения сохранены, но фотографии не загрузились: ' . $exception->getMessage());
                }
            }
            $this->addFlash('success', 'Изменения сохранены');
            return $this->redirectToRoute(
                'account_wardrobe_show',
                ['id' => $item->getId()] + $this->memberQuery($user, $currentMember),
            );
        }

        return $this->render('account/wardrobe/form.html.twig', [
            'form'          => $form,
            'item'          => $item,
            'currentMember' => $currentMember,
            'isOwnWardrobe' => $currentMember->getId() === $user->getId(),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        WardrobeItemRepository $repo,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('delete_wardrobe_item', $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('account_wardrobe_index');
        }

        /** @var User $user */
        $user = $this->getUser();

        $currentMember = $this->familyService->resolveMember($user, $this->memberParam($request));

        $item = $repo->findActiveOneForUser($id, $currentMember);
        if (!$item) {
            throw $this->createNotFoundException();
        }

        // Только soft-delete — физический DELETE по действию пользователя запрещён
        $item->softDelete();
        $em->flush();
        $this->addFlash('success', 'Вещь удалена');

        return $this->redirectToRoute('account_wardrobe_index', $this->memberQuery($user, $currentMember));
    }

    #[Route('/{id}/transfer', name: 'transfer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function transfer(
        int $id,
        Request $request,
        WardrobeItemRepository $repo,
        ManagerRegistry $doctrine,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $currentMember = $this->familyService->resolveMember($user, $this->memberParam($request));

        if (!$this->isCsrfTokenValid('transfer_wardrobe_item', $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('account_wardrobe_index', $this->memberQuery($user, $currentMember));
        }

        if (!$user->isFamilyParent()) {
            throw $this->createAccessDeniedException('Передавать вещи может только родитель');
        }

        $item = $repo->findActiveOneForUser($id, $currentMember);
        if (!$item) {
            throw $this->createNotFoundException();
        }

        $toUserId = $request->request->getInt('to_user');
        $toUser = null;
        foreach ($this->familyService->membersFor($user) as $member) {
            if ($member->getId() === $toUserId) {
                $toUser = $member;
                break;
            }
        }

        if ($toUser === null || $toUser->getId() === $item->getUser()->getId()) {
            $this->addFlash('error', 'Некорректный получатель передачи');
            return $this->redirectToRoute(
                'account_wardrobe_show',
                ['id' => $id] + $this->memberQuery($user, $currentMember),
            );
        }

        $note = trim((string) $request->request->get('note')) ?: null;
        $fromUserId = $item->getUser()->getId();

        $em = $doctrine->getManager();
        try {
            $this->applyTransfer($em, $repo, $item, $item->getUser(), $toUser, $user, $note);
        } catch (UniqueConstraintViolationException) {
            // Гонка за item_no у получателя: один retry со свежим EM (паттерн как в new())
            $doctrine->resetManager();
            $em = $doctrine->getManager();
            $item   = $em->find(WardrobeItem::class, $id);
            $toUser = $em->find(User::class, $toUserId);
            $this->applyTransfer(
                $em,
                $repo,
                $item,
                $em->find(User::class, $fromUserId),
                $toUser,
                $em->find(User::class, $user->getId()),
                $note,
            );
        }

        $this->addFlash('success', sprintf('Вещь передана %s', $toUser->getFullName()));

        return $this->redirectToRoute(
            'account_wardrobe_show',
            ['id' => $id] + ($toUser->getId() !== $user->getId() ? ['member' => $toUser->getId()] : []),
        );
    }

    #[Route('/{id}/status', name: 'status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function status(
        int $id,
        Request $request,
        WardrobeItemRepository $repo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $currentMember = $this->familyService->resolveMember($user, $this->memberParam($request));

        if (!$this->isCsrfTokenValid('status_wardrobe_item', $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('account_wardrobe_index', $this->memberQuery($user, $currentMember));
        }

        $item = $repo->findActiveOneForUser($id, $currentMember);
        if (!$item) {
            throw $this->createNotFoundException();
        }

        $wearStatus = (string) $request->request->get('wear_status');
        if (!array_key_exists($wearStatus, WardrobeItem::WEAR_LABELS)) {
            $this->addFlash('error', 'Недопустимый статус');
        } else {
            $item->setWearStatus($wearStatus);
            $em->flush();
            $this->addFlash('success', sprintf('Статус обновлён: %s', WardrobeItem::WEAR_LABELS[$wearStatus]));
        }

        return $this->redirectToRoute(
            'account_wardrobe_show',
            ['id' => $id] + $this->memberQuery($user, $currentMember),
        );
    }

    /**
     * Собственно передача: журнал (append-only) + смена носителя и его сквозного номера.
     * item.id стабилен, original_owner не трогаем (immutable).
     */
    private function applyTransfer(
        \Doctrine\Persistence\ObjectManager $em,
        WardrobeItemRepository $repo,
        WardrobeItem $item,
        User $fromUser,
        User $toUser,
        User $actor,
        ?string $note,
    ): void {
        $transfer = new WardrobeTransfer();
        $transfer->setItem($item);
        $transfer->setFromUser($fromUser);
        $transfer->setToUser($toUser);
        $transfer->setActor($actor);
        $transfer->setNote($note);

        $item->setUser($toUser);
        $item->setItemNo($repo->nextItemNo($toUser));

        $em->persist($transfer);
        $em->flush();
    }

    /**
     * @return array{User, User, WardrobeItem, WardrobeItemPhoto}
     */
    private function resolvePhotoAction(
        int $itemId,
        int $photoId,
        Request $request,
        WardrobeItemRepository $repo,
        EntityManagerInterface $em,
    ): array {
        /** @var User $user */
        $user = $this->getUser();
        $currentMember = $this->familyService->resolveMember($user, $this->memberParam($request));
        $item = $repo->findActiveOneForUser($itemId, $currentMember);
        $photo = $em->find(WardrobeItemPhoto::class, $photoId);
        if (!$item || !$photo || $photo->getItem()?->getId() !== $item->getId()) {
            throw $this->createNotFoundException();
        }

        return [$user, $currentMember, $item, $photo];
    }

    /**
     * ?member= из query (null = свой гардероб — прежнее поведение).
     */
    private function memberParam(Request $request): ?int
    {
        return $request->query->has('member') ? $request->query->getInt('member') : null;
    }

    private function sanitizeItemPhoto(WardrobeItem $item): void
    {
        $file = $item->getPhotoFile();
        if ($file instanceof UploadedFile) {
            $item->setPhotoFile($this->imageSanitizer->sanitize($file));
        }
    }

    /** @return array{q: string, category: string, brand: string, color: string, size: string, season: string, completion: string, status: string, wear: string} */
    private function wardrobeFilters(Request $request): array
    {
        $filters = [];
        foreach (['q', 'category', 'brand', 'color', 'size', 'season', 'completion', 'status', 'wear'] as $name) {
            $filters[$name] = mb_substr(trim((string) $request->query->get($name, '')), 0, 100);
        }

        if (!array_key_exists($filters['completion'], WardrobeItem::COMPLETION_LABELS)) {
            $filters['completion'] = '';
        }
        if (!array_key_exists($filters['status'], WardrobeItem::ITEM_LABELS)) {
            $filters['status'] = '';
        }
        if (!array_key_exists($filters['wear'], WardrobeItem::WEAR_LABELS)) {
            $filters['wear'] = '';
        }

        return $filters;
    }

    /**
     * member=<id> для redirect'ов — только когда смотрим чужой гардероб
     * (обратная совместимость: свои redirect'ы остаются без query-параметров).
     *
     * @return array{member?: int}
     */
    private function memberQuery(User $actor, User $currentMember): array
    {
        return $currentMember->getId() === $actor->getId()
            ? []
            : ['member' => $currentMember->getId()];
    }
}
