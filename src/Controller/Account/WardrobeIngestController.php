<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\WardrobeItemDraft;
use App\Repository\WardrobeItemDraftRepository;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeDraftPromotionService;
use App\Service\Wardrobe\WardrobeOnboardingService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Авто-инжест гардероба по фото: загрузка партии → фоновое распознавание
 * (app:wardrobe:ingest-drafts) → ревью/правка/принятие в WardrobeItem.
 * Черновики (WardrobeItemDraft) — стейджинг, hard-delete допустим (не подпадает
 * под правило soft-delete: не пользовательские данные о вещи).
 */
#[Route('/account/wardrobe/ingest', name: 'account_wardrobe_ingest_')]
class WardrobeIngestController extends AbstractController
{
    public function __construct(
        private readonly FamilyService $familyService,
        private readonly WardrobeOnboardingService $onboardingService,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function upload(
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        WardrobeItemDraftRepository $drafts,
        RateLimiterFactory $wardrobeIngestLimiter,
    ): JsonResponse {
        if ($fail = $this->csrfOrFail($request)) {
            return $fail;
        }

        /** @var User $actor */
        $actor = $this->getUser();
        if (!$wardrobeIngestLimiter->create((string) $actor->getId())->consume()->isAccepted()) {
            return $this->json(['ok' => false, 'error' => 'Слишком много загрузок — попробуйте позже'], 429);
        }
        $memberId = $request->request->getInt('member') ?: $request->query->getInt('member');
        $subject = $this->familyService->resolveMember($actor, $memberId > 0 ? $memberId : null);

        $files = $request->files->get('photos', []);
        if (!is_array($files)) {
            $files = [$files];
        }
        if (count($files) > 20) {
            return $this->json(['ok' => false, 'error' => 'За один раз можно загрузить не больше 20 фотографий'], 422);
        }

        $constraint = new Image([
            'maxSize' => '10M',
            'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
            'mimeTypesMessage' => 'Формат не поддерживается — сконвертируйте в JPEG/PNG (iPhone: Настройки→Камера→Форматы→Наиболее совместимый)',
        ]);

        $acceptedFiles = [];
        $duplicates = [];
        $rejected = [];
        $seenHashes = [];

        foreach ($files as $file) {
            if (!$file) {
                continue;
            }

            $name = $file instanceof UploadedFile ? $file->getClientOriginalName() : 'файл';
            $violations = $validator->validate($file, $constraint);
            if ($violations->count() > 0) {
                $rejected[] = ['name' => $name, 'reason' => $violations->get(0)->getMessage()];
                continue;
            }

            $hash = hash_file('sha256', $file->getPathname());
            $duplicate = is_string($hash) ? $drafts->findDuplicate($subject, $hash) : null;
            if (!is_string($hash) || isset($seenHashes[$hash]) || $duplicate !== null) {
                $duplicates[] = ['name' => $name, 'draftId' => $duplicate?->getId()];
                continue;
            }

            $seenHashes[$hash] = true;
            $acceptedFiles[] = ['file' => $file, 'hash' => $hash];
        }

        if ($acceptedFiles === []) {
            if ($duplicates !== []) {
                return $this->json([
                    'ok' => true,
                    'uploaded' => 0,
                    'duplicates' => $duplicates,
                    'rejected' => $rejected,
                    'reviewUrl' => $this->generateUrl('account_wardrobe_index', $subject->getId() === $actor->getId()
                        ? []
                        : ['member' => $subject->getId()]),
                ]);
            }
            return $this->json([
                'ok' => false,
                'error' => $rejected === [] ? 'Выберите хотя бы одну фотографию' : 'Не удалось принять ни одной фотографии',
                'uploaded' => 0,
                'rejected' => $rejected,
            ], 422);
        }

        $onboarding = $this->onboardingService->startOrResumeBatch($actor, $subject, Uuid::v4()->toRfc4122());
        $batchId = $onboarding->getActiveBatchId();
        $uploaded = $em->wrapInTransaction(function (EntityManagerInterface $em) use (
            $acceptedFiles,
            $actor,
            $subject,
            $batchId,
            $drafts,
            &$duplicates,
        ): int {
            $em->lock($subject, LockMode::PESSIMISTIC_WRITE);
            $created = 0;
            foreach ($acceptedFiles as $acceptedFile) {
                $duplicate = $drafts->findDuplicate($subject, $acceptedFile['hash']);
                if ($duplicate !== null) {
                    $duplicates[] = ['name' => $acceptedFile['file']->getClientOriginalName(), 'draftId' => $duplicate->getId()];
                    continue;
                }
                $draft = (new WardrobeItemDraft())
                    ->setProfileSubject($subject)
                    ->setActor($actor)
                    ->setBatchId($batchId)
                    ->setContentHash($acceptedFile['hash']);
                $draft->setPhotoFile($acceptedFile['file']);
                $em->persist($draft);
                $created++;
            }
            $em->flush();

            return $created;
        });

        return $this->json([
            'ok' => true,
            'batch' => $batchId,
            'uploaded' => $uploaded,
            'duplicates' => $duplicates,
            'rejected' => $rejected,
            'reviewUrl' => $this->generateUrl('account_wardrobe_ingest_review', [
                'batch' => $batchId,
                'member' => $subject->getId(),
            ]),
        ]);
    }

    #[Route('/{batch}', name: 'review', methods: ['GET'])]
    public function review(string $batch, Request $request, WardrobeItemDraftRepository $draftRepo): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $subject = $this->familyService->resolveMember($actor, $this->memberParam($request));

        $drafts = $draftRepo->findByBatch($subject, $batch);
        if ($drafts === []) {
            throw $this->createNotFoundException();
        }

        return $this->render('account/wardrobe/ingest.html.twig', [
            'drafts' => $drafts,
            'batch' => $batch,
            'counts' => $draftRepo->countsByBatch($subject, $batch),
            'currentMember' => $subject,
        ]);
    }

    #[Route('/{batch}/status', name: 'status', methods: ['GET'])]
    public function status(string $batch, Request $request, WardrobeItemDraftRepository $draftRepo): JsonResponse
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $subject = $this->familyService->resolveMember($actor, $this->memberParam($request));

        if ($draftRepo->findByBatch($subject, $batch) === []) {
            throw $this->createNotFoundException();
        }

        return $this->json($draftRepo->countsByBatch($subject, $batch));
    }

    #[Route('/draft/{id}/accept', name: 'accept', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function accept(
        int $id,
        Request $request,
        WardrobeItemDraftRepository $draftRepo,
        WardrobeDraftPromotionService $promotion,
    ): JsonResponse {
        if ($fail = $this->csrfOrFail($request)) {
            return $fail;
        }

        /** @var User $user */
        $user = $this->getUser();

        $draft = $draftRepo->find($id);
        if ($draft === null || !$this->familyService->canManage($user, $draft->getUser())) {
            throw $this->createNotFoundException();
        }

        try {
            $result = $promotion->promote($user, $id, $this->decodeBody($request));
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return $this->json(['ok' => false, 'error' => $exception->getMessage()], 422);
        }
        $item = $result['item'];
        $this->onboardingService->refreshProgress($user, $draft->getUser());

        return $this->json([
            'ok' => true,
            'itemId' => $item->getId(),
            'itemNo' => $item->getItemNo(),
            'idempotent' => $result['idempotent'],
        ]);
    }

    #[Route('/draft/{id}/reject', name: 'reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(
        int $id,
        Request $request,
        WardrobeItemDraftRepository $draftRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        if ($fail = $this->csrfOrFail($request)) {
            return $fail;
        }

        /** @var User $user */
        $user = $this->getUser();

        $draft = $draftRepo->find($id);
        if ($draft === null || !$this->familyService->canManage($user, $draft->getUser())) {
            throw $this->createNotFoundException();
        }

        $subject = $draft->getUser();
        $em->remove($draft);
        $em->flush();
        $this->onboardingService->refreshProgress($user, $subject);

        return $this->json(['ok' => true]);
    }

    #[Route('/{batch}/accept-all', name: 'accept_all', methods: ['POST'])]
    public function acceptAll(
        string $batch,
        Request $request,
        WardrobeItemDraftRepository $draftRepo,
        WardrobeDraftPromotionService $promotion,
    ): JsonResponse {
        if ($fail = $this->csrfOrFail($request)) {
            return $fail;
        }

        /** @var User $user */
        $user = $this->getUser();

        $subject = $this->familyService->resolveMember($user, $this->memberParam($request));
        $drafts = $draftRepo->findByBatch($subject, $batch);
        if ($drafts === []) {
            throw $this->createNotFoundException();
        }

        $accepted = 0;
        $skipped = 0;

        foreach ($drafts as $draft) {
            if ($draft->getStatus() !== WardrobeItemDraft::STATUS_RECOGNIZED
                || !in_array($draft->getConfidence(), ['high', 'med'], true)
            ) {
                $skipped++;
                continue;
            }

            try {
                $result = $promotion->promote($user, $draft->getId(), []);
                $result['idempotent'] ? $skipped++ : $accepted++;
            } catch (\Throwable $exception) {
                // Одна неудача не должна валить весь батч — считаем как пропуск и продолжаем
                $this->logger->error('Не удалось принять wardrobe draft из пачки', [
                    'draft_id' => $draft->getId(),
                    'exception' => $exception,
                ]);
                $skipped++;
            }
        }
        $this->onboardingService->refreshProgress($user, $subject);

        return $this->json(['ok' => true, 'accepted' => $accepted, 'skipped' => $skipped]);
    }

    /** JSON- или form-тело запроса; пустое тело → пустые overrides (используются значения драфта). */
    private function decodeBody(Request $request): array
    {
        if ($request->getContent() === '') {
            return $request->request->all();
        }

        try {
            return $request->toArray();
        } catch (\Throwable) {
            return $request->request->all();
        }
    }

    private function csrfOrFail(Request $request): ?JsonResponse
    {
        if (!$this->isCsrfTokenValid('wardrobe_ingest', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['ok' => false, 'error' => 'Недействительный токен'], 419);
        }

        return null;
    }

    private function memberParam(Request $request): ?int
    {
        $member = $request->query->getInt('member');

        return $member > 0 ? $member : null;
    }
}
