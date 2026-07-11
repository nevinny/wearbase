<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeTransfer;
use App\Form\Account\WardrobeItemFormType;
use App\Repository\WardrobeItemRepository;
use App\Repository\WardrobeTransferRepository;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeAiService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account/wardrobe', name: 'account_wardrobe_')]
class WardrobeController extends AbstractController
{
    public function __construct(private readonly FamilyService $familyService) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, WardrobeItemRepository $repo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $currentMember = $this->familyService->resolveMember($user, $this->memberParam($request));

        $items = $repo->findActiveForUser($currentMember);
        $stats = $repo->getStats($currentMember);

        return $this->render('account/wardrobe/index.html.twig', [
            'items'         => $items,
            'stats'         => $stats,
            'totalCount'    => (int) array_sum(array_column($stats, 'cnt')),
            'totalSum'      => array_sum(array_map('floatval', array_column($stats, 'total'))),
            'members'       => $this->familyService->membersFor($user),
            'currentMember' => $currentMember,
            'isOwnWardrobe' => $currentMember->getId() === $user->getId(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        WardrobeItemRepository $repo,
        ManagerRegistry $doctrine,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $currentMember = $this->familyService->resolveMember($user, $this->memberParam($request));

        $item = new WardrobeItem();
        $form = $this->createForm(WardrobeItemFormType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $item->setUser($currentMember);
            $item->setOriginalOwner($currentMember);
            $item->setItemNo($repo->nextItemNo($currentMember));
            $item->setSource(WardrobeItem::SOURCE_WEB);

            $em = $doctrine->getManager();
            try {
                $em->persist($item);
                $em->flush();
            } catch (UniqueConstraintViolationException) {
                // Гонка за item_no: один retry со свежим номером (EM после исключения закрыт)
                $doctrine->resetManager();
                $em = $doctrine->getManager();
                /** @var User $currentMember */
                $currentMember = $em->find(User::class, $currentMember->getId());
                $item->setUser($currentMember);
                $item->setOriginalOwner($currentMember);
                $item->setItemNo($repo->nextItemNo($currentMember));
                $em->persist($item);
                $em->flush();
            }

            $this->addFlash('success', 'Вещь добавлена');
            return $this->redirectToRoute('account_wardrobe_index', $this->memberQuery($user, $currentMember));
        }

        return $this->render('account/wardrobe/form.html.twig', [
            'form'          => $form,
            'item'          => $item,
            'categories'    => array_unique(array_merge(
                $repo->distinctCategories($currentMember),
                WardrobeItem::SUGGESTED_CATEGORIES,
            )),
            'currentMember' => $currentMember,
            'isOwnWardrobe' => $currentMember->getId() === $user->getId(),
        ]);
    }

    /**
     * AI-ассист: распознать параметры вещи по фото (vision LLM). Контракт ответа
     * фиксирован для JS-виджета формы — не менять без синхронизации с фронтом.
     */
    #[Route('/ai/photo', name: 'ai_photo', methods: ['POST'])]
    public function aiPhoto(
        Request $request,
        WardrobeAiService $ai,
        RateLimiterFactory $wardrobeAiLimiter,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('wardrobe_ai', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'error' => 'Недействительный токен'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();
        if (!$wardrobeAiLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            return $this->json(['ok' => false, 'error' => 'Лимит AI-подсказок на сегодня'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $photo = $request->files->get('photo');
        if (!$photo instanceof UploadedFile || !$photo->isValid()) {
            return $this->json(['ok' => false, 'error' => 'Файл не получен'], Response::HTTP_BAD_REQUEST);
        }
        if (!str_starts_with((string) $photo->getMimeType(), 'image/')) {
            return $this->json(['ok' => false, 'error' => 'Нужен файл изображения'], Response::HTTP_BAD_REQUEST);
        }
        if ($photo->getSize() > 10 * 1024 * 1024) {
            return $this->json(['ok' => false, 'error' => 'Файл больше 10 МБ'], Response::HTTP_BAD_REQUEST);
        }

        $result = $ai->suggestFromPhoto($photo->getPathname());

        return $this->json($result, $result['ok'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
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
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('wardrobe_ai', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'error' => 'Недействительный токен'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();
        if (!$wardrobeAiLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            return $this->json(['ok' => false, 'error' => 'Лимит AI-подсказок на сегодня'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $result = $ai->suggestFromUrl((string) $request->request->get('url'));

        return $this->json($result, $result['ok'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        int $id,
        Request $request,
        WardrobeItemRepository $repo,
        WardrobeTransferRepository $transferRepo,
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
            'transferTargets' => $transferTargets,
            'canManage'       => $this->familyService->canManage($user, $currentMember),
            'currentMember'   => $currentMember,
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

        $form = $this->createForm(WardrobeItemFormType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Изменения сохранены');
            return $this->redirectToRoute(
                'account_wardrobe_show',
                ['id' => $item->getId()] + $this->memberQuery($user, $currentMember),
            );
        }

        return $this->render('account/wardrobe/form.html.twig', [
            'form'          => $form,
            'item'          => $item,
            'categories'    => array_unique(array_merge(
                $repo->distinctCategories($currentMember),
                WardrobeItem::SUGGESTED_CATEGORIES,
            )),
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
     * ?member= из query (null = свой гардероб — прежнее поведение).
     */
    private function memberParam(Request $request): ?int
    {
        return $request->query->has('member') ? $request->query->getInt('member') : null;
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
