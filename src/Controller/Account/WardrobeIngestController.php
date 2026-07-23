<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemDraft;
use App\Repository\WardrobeItemDraftRepository;
use App\Repository\WardrobeItemRepository;
use App\Service\Wardrobe\WardrobeManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Авто-инжест гардероба по фото: загрузка партии → фоновое распознавание
 * (app:wardrobe:ingest-drafts) → ревью/правка/принятие в WardrobeItem.
 * Черновики (WardrobeItemDraft) — стейджинг, hard-delete допустим (не подпадает
 * под правило soft-delete: не пользовательские данные о вещи).
 */
#[Route('/account/wardrobe/ingest', name: 'account_wardrobe_ingest_')]
class WardrobeIngestController extends AbstractController
{
    public function __construct(private readonly WardrobeManager $wardrobeManager) {}

    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function upload(
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
    ): JsonResponse {
        if ($fail = $this->csrfOrFail($request)) {
            return $fail;
        }

        /** @var User $user */
        $user = $this->getUser();

        $files = $request->files->get('photos', []);
        if (!is_array($files)) {
            $files = [$files];
        }

        $constraint = new Image([
            'maxSize' => '10M',
            'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
            'mimeTypesMessage' => 'Формат не поддерживается — сконвертируйте в JPEG/PNG (iPhone: Настройки→Камера→Форматы→Наиболее совместимый)',
        ]);

        $batchId = Uuid::v4()->toRfc4122();
        $uploaded = 0;
        $rejected = [];

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

            $draft = new WardrobeItemDraft();
            $draft->setUser($user);
            $draft->setBatchId($batchId);
            $draft->setPhotoFile($file);
            $em->persist($draft);
            $uploaded++;
        }

        $em->flush();

        return $this->json([
            'ok' => true,
            'batch' => $batchId,
            'uploaded' => $uploaded,
            'rejected' => $rejected,
            'reviewUrl' => $this->generateUrl('account_wardrobe_ingest_review', ['batch' => $batchId]),
        ]);
    }

    #[Route('/{batch}', name: 'review', methods: ['GET'])]
    public function review(string $batch, WardrobeItemDraftRepository $draftRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $drafts = $draftRepo->findByBatch($user, $batch);
        if ($drafts === []) {
            throw $this->createNotFoundException();
        }

        return $this->render('account/wardrobe/ingest.html.twig', [
            'drafts' => $drafts,
            'batch' => $batch,
            'counts' => $draftRepo->countsByBatch($batch),
        ]);
    }

    #[Route('/{batch}/status', name: 'status', methods: ['GET'])]
    public function status(string $batch, WardrobeItemDraftRepository $draftRepo): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($draftRepo->findByBatch($user, $batch) === []) {
            throw $this->createNotFoundException();
        }

        return $this->json($draftRepo->countsByBatch($batch));
    }

    #[Route('/draft/{id}/accept', name: 'accept', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function accept(
        int $id,
        Request $request,
        WardrobeItemDraftRepository $draftRepo,
        WardrobeItemRepository $itemRepo,
        ManagerRegistry $doctrine,
        StorageInterface $vichStorage,
    ): JsonResponse {
        if ($fail = $this->csrfOrFail($request)) {
            return $fail;
        }

        /** @var User $user */
        $user = $this->getUser();

        $draft = $draftRepo->find($id);
        if ($draft === null || $draft->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        [$item, $error] = $this->promoteDraft($draft, $this->decodeBody($request), $itemRepo, $doctrine, $vichStorage);
        if ($error !== null) {
            return $this->json(['ok' => false, 'error' => $error], 422);
        }

        return $this->json(['ok' => true, 'itemId' => $item->getId(), 'itemNo' => $item->getItemNo()]);
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
        if ($draft === null || $draft->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        $em->remove($draft);
        $em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/{batch}/accept-all', name: 'accept_all', methods: ['POST'])]
    public function acceptAll(
        string $batch,
        Request $request,
        WardrobeItemDraftRepository $draftRepo,
        WardrobeItemRepository $itemRepo,
        ManagerRegistry $doctrine,
        StorageInterface $vichStorage,
    ): JsonResponse {
        if ($fail = $this->csrfOrFail($request)) {
            return $fail;
        }

        /** @var User $user */
        $user = $this->getUser();

        $drafts = $draftRepo->findByBatch($user, $batch);
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
                [, $error] = $this->promoteDraft($draft, [], $itemRepo, $doctrine, $vichStorage);
                $error === null ? $accepted++ : $skipped++;
            } catch (\Throwable) {
                // Одна неудача не должна валить весь батч — считаем как пропуск и продолжаем
                $skipped++;
            }
        }

        return $this->json(['ok' => true, 'accepted' => $accepted, 'skipped' => $skipped]);
    }

    /**
     * Промоушен черновика в полноценную вещь гардероба — общая логика для accept/accept-all.
     * Мирроринг WardrobeController::new (itemNo + retry) и WardrobeDialogService::commitDraft
     * (перенос файла между Vich-маппингами через tmp-копию).
     *
     * @param array{name?:string,category?:string,size?:string,notes?:string} $overrides
     * @return array{0: ?WardrobeItem, 1: ?string} [созданная вещь|null, текст ошибки|null]
     */
    private function promoteDraft(
        WardrobeItemDraft $draft,
        array $overrides,
        WardrobeItemRepository $itemRepo,
        ManagerRegistry $doctrine,
        StorageInterface $vichStorage,
    ): array {
        $category = $this->pick($overrides['category'] ?? null, $draft->getCategory());
        $name = $this->pick($overrides['name'] ?? null, $draft->getName());
        $size = $this->pick($overrides['size'] ?? null, $draft->getSize());
        $notes = $this->pick($overrides['notes'] ?? null, $draft->getNotes());

        // WardrobeItem::category/name не nullable — без обоих полей вещь не создать
        if ($category === null || $name === null) {
            return [null, 'Заполните категорию и название'];
        }

        $user = $draft->getUser();

        $item = new WardrobeItem();
        $item->setCategory($category);
        $item->setName($name);
        $item->setSize($size);
        $item->setNotes($notes);
        $item->setSource(WardrobeItem::SOURCE_IMPORT);
        $item->setUser($user);
        $item->setWardrobe($this->wardrobeManager->getOrCreateDefault($user));
        $item->setOriginalOwner($user);
        $item->setItemNo($itemRepo->nextItemNo($user));

        // Фото: draft photoFile (mapping wardrobe_draft_photo) → tmp-копия → item photoFile
        // (mapping wardrobe_item_photo), как в WardrobeDialogService::commitDraft.
        $draftPhotoPath = $draft->getPhoto() !== null ? $vichStorage->resolvePath($draft, 'photoFile') : null;
        if ($draftPhotoPath !== null && is_file($draftPhotoPath)) {
            $tmp = tempnam(sys_get_temp_dir(), 'wardrobe_ingest_');
            copy($draftPhotoPath, $tmp);
            $mime = MimeTypes::getDefault()->guessMimeType($tmp) ?? 'image/jpeg';
            $item->setPhotoFile(new UploadedFile($tmp, basename($draftPhotoPath), $mime, null, true));
        }

        $em = $doctrine->getManager();
        try {
            $em->persist($item);
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            // Гонка за item_no: один retry со свежим EM (паттерн WardrobeController::new)
            $doctrine->resetManager();
            $em = $doctrine->getManager();
            /** @var User $user */
            $user = $em->find(User::class, $user->getId());
            $item->setUser($user);
            $item->setOriginalOwner($user);
            $item->setItemNo($itemRepo->nextItemNo($user));
            if ($item->getPhotoFile() !== null && !file_exists($item->getPhotoFile()->getPathname())) {
                $item->setPhotoFile(null); // Vich мог успеть переместить tmp-файл при первой попытке
            }
            $em->persist($item);
            $em->flush();
        }

        // Черновик удаляем СВЕЖИМ EM (мог смениться после retry выше) — hard-delete,
        // Vich подчищает файл draft'а из wardrobe_draft_photo при remove.
        $em = $doctrine->getManager();
        $freshDraft = $em->find(WardrobeItemDraft::class, $draft->getId());
        if ($freshDraft !== null) {
            $em->remove($freshDraft);
            $em->flush();
        }

        return [$item, null];
    }

    private function pick(?string $override, ?string $fallback): ?string
    {
        $override = $override !== null ? trim($override) : '';
        if ($override !== '') {
            return $override;
        }

        $fallback = $fallback !== null ? trim($fallback) : '';

        return $fallback !== '' ? $fallback : null;
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
}
