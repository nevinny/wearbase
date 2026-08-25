<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\WardrobeCircle;
use App\Entity\WardrobeCircleInvite;
use App\Entity\WardrobeCircleMember;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemPhoto;
use App\Repository\WardrobeCircleInviteRepository;
use App\Repository\WardrobeCircleMemberRepository;
use App\Repository\WardrobeOutfitShareRepository;
use App\Service\Circle\CircleService;
use App\Service\FamilyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Кружки подруг (docs/circles-spec.md): список/создание, лента, выход, кик,
 * инвайты, join по токену. Всё за существующим firewall-правилом ^/account —
 * акцепт инвайта только залогиненным (§1.4), security.yaml не меняется.
 *
 * Авторизация — в CircleService (не Voter'ы); доменные ошибки → flash,
 * чужие кружки → нейтральный 404.
 */
#[Route('/account/circles')]
class CircleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CircleService $circles,
        private readonly FamilyService $families,
        private readonly WardrobeCircleMemberRepository $memberships,
        private readonly WardrobeCircleInviteRepository $invites,
        private readonly WardrobeOutfitShareRepository $shares,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    // ── Список и создание ────────────────────────────────────────────────────

    #[Route('', name: 'account_circles', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();

        return $this->render('account/wardrobe/circles.html.twig', [
            'memberships' => $this->visibleMemberships($actor),
            'pendingForParent' => $actor->isFamilyParent() && $actor->getFamily() !== null
                ? $this->memberships->findPendingParentFor($actor)
                : [],
            'managedChildren' => array_values(array_filter(
                $this->families->membersFor($actor),
                static fn (User $m): bool => $m->getId() !== $actor->getId() && $m->isManaged(),
            )),
        ]);
    }

    #[Route('', name: 'account_circles_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('circle_create', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недействительный токен');
        }

        /** @var User $actor */
        $actor = $this->getUser();
        try {
            $circle = $this->circles->create($actor, mb_substr(trim((string) $request->request->get('title')), 0, WardrobeCircle::TITLE_MAX));
            $this->addFlash('success', 'Кружок «'.$circle->getTitle().'» создан');
        } catch (\DomainException | AccessDeniedException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('account_circles');
    }

    // ── Лента ────────────────────────────────────────────────────────────────

    #[Route('/{id}', name: 'account_circles_feed', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function feed(int $id): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $circle = $this->em->find(WardrobeCircle::class, $id);
        if (!$circle instanceof WardrobeCircle || !$this->circles->canViewFeed($actor, $circle)) {
            throw $this->createNotFoundException();
        }
        $membership = $this->memberships->findOneBy(['circle' => $circle, 'user' => $actor]);

        $cards = [];
        foreach ($this->shares->findActiveForCircle($circle) as $share) {
            $cards[] = [
                'id' => $share->getId(),
                'outfitId' => $share->getOutfit()->getId(),
                'title' => $share->getOutfit()->getTitle(),
                'explanation' => $share->getOutfit()->getExplanation(),
                'authorFirstName' => $share->getOutfit()->getUser()?->getFirstName()
                    ?? $share->getOutfit()->getWardrobeOwner()?->getFirstName(),
                'grantedAt' => $share->getGrantedAt(),
                'items' => $this->feedItems($share),
            ];
        }

        return $this->render('account/wardrobe/circle_feed.html.twig', [
            'circle' => $circle,
            'membership' => $membership,
            'participants' => $this->memberships->findListedForCircle($circle),
            'activeInvites' => in_array($membership?->getRole(), [WardrobeCircleMember::ROLE_OWNER, WardrobeCircleMember::ROLE_MODERATOR], true)
                ? $this->invites->findActiveForCircle($circle)
                : [],
            'cards' => $cards,
            'canKick' => in_array($membership?->getRole(), [WardrobeCircleMember::ROLE_OWNER, WardrobeCircleMember::ROLE_MODERATOR], true),
        ]);
    }

    // ── Выход / кик / подтверждение родителя ─────────────────────────────────

    #[Route('/{id}/leave', name: 'account_circles_leave', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function leave(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('circle_leave_'.$id, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недействительный токен');
        }

        /** @var User $actor */
        $actor = $this->getUser();
        $circle = $this->em->find(WardrobeCircle::class, $id);
        if ($circle === null) {
            throw $this->createNotFoundException();
        }

        try {
            $this->circles->leave($actor, $circle, $request->request->getInt('successor') ?: null);
            $this->addFlash('success', 'Вы вышли из кружка');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (AccessDeniedException) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute('account_circles');
    }

    #[Route('/{id}/kick', name: 'account_circles_kick', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function kick(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('circle_kick_'.$id, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недействительный токен');
        }

        /** @var User $actor */
        $actor = $this->getUser();
        $circle = $this->em->find(WardrobeCircle::class, $id);
        if ($circle === null) {
            throw $this->createNotFoundException();
        }

        try {
            $this->circles->kick($actor, $circle, $request->request->getInt('member'));
            $this->addFlash('success', 'Участник исключён');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (AccessDeniedException) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute('account_circles_feed', ['id' => $id]);
    }

    #[Route('/member/{id}/confirm', name: 'account_circles_member_confirm', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function confirmMember(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('circle_member_confirm_'.$id, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недействительный токен');
        }

        /** @var User $actor */
        $actor = $this->getUser();
        $membership = $this->em->find(WardrobeCircleMember::class, $id);
        if ($membership === null) {
            throw $this->createNotFoundException();
        }

        try {
            $approve = $request->request->get('action') === 'approve';
            $this->circles->confirmMembership($actor, $membership, $approve);
            $this->addFlash('success', $approve ? 'Участие ребёнка в кружке подтверждено' : 'Участие отклонено');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (AccessDeniedException) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute('account_circles');
    }

    /** Отзыв родительского согласия: массовый revoke кружковых share детских луков (§3.1). */
    #[Route('/consent/{userId}/revoke-shares', name: 'account_circles_child_shares_revoke', requirements: ['userId' => '\\d+'], methods: ['POST'])]
    public function revokeChildShares(int $userId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('circle_child_shares_'.$userId, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недействительный токен');
        }

        /** @var User $actor */
        $actor = $this->getUser();
        $child = $this->em->find(User::class, $userId);
        if ($child === null) {
            throw $this->createNotFoundException();
        }

        try {
            $count = $this->circles->revokeChildCircleShares($actor, $child);
            $this->addFlash('success', $count > 0
                ? sprintf('Отозвано кружковых ссылок ребёнка: %d', $count)
                : 'Активных кружковых ссылок ребёнка нет');
        } catch (AccessDeniedException) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute('account_circles');
    }

    // ── Инвайты ──────────────────────────────────────────────────────────────

    /** Повторное «Пригласить» = новый токен: прежние активные ссылки отзывается. */
    #[Route('/{id}/invite', name: 'account_circles_invite_create', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function createInvite(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('circle_invite_'.$id, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недействительный токен');
        }

        /** @var User $actor */
        $actor = $this->getUser();
        $circle = $this->em->find(WardrobeCircle::class, $id);
        if ($circle === null) {
            throw $this->createNotFoundException();
        }

        try {
            // Повторное «Пригласить» = новый токен: прежняя активная ссылка отзывается.
            $previous = $this->invites->findActiveForCircle($circle)[0] ?? null;
            $invite = $previous !== null
                ? $this->circles->renewInvite($actor, $previous)
                : $this->circles->createInvite($actor, $circle);
            $this->addFlash('success', 'Новая ссылка-приглашение готова');
        } catch (\DomainException | AccessDeniedException $exception) {
            $this->addFlash('error', $exception->getMessage());
            return $this->redirectToRoute('account_circles');
        }

        return $this->redirectToRoute('account_circles_feed', ['id' => $id]);
    }

    #[Route('/invite/{id}/revoke', name: 'account_circles_invite_revoke', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function revokeInvite(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('circle_invite_revoke_'.$id, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недействительный токен');
        }

        /** @var User $actor */
        $actor = $this->getUser();
        $invite = $this->em->find(WardrobeCircleInvite::class, $id);
        if ($invite === null) {
            throw $this->createNotFoundException();
        }

        try {
            $this->circles->revokeInvite($actor, $invite);
            $this->addFlash('success', 'Ссылка отозвана');
        } catch (\DomainException | AccessDeniedException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('account_circles_feed', ['id' => $invite->getCircle()->getId()]);
    }

    /**
     * Join по ссылке — под firewall'ом: акцепт только залогиненным (§1.4).
     * Истечение/отзыв → нейтральный 410 + no-store; долбёжка токена лимитируется
     * парой token+IP.
     */
    #[Route('/join/{token}', name: 'account_circle_join', requirements: ['token' => '[0-9a-f]{64}'], methods: ['GET', 'POST'])]
    public function join(string $token, Request $request, RateLimiterFactory $circleJoinLimiter): Response
    {
        if (!$circleJoinLimiter->create($this->limiterKey($request, $token))->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        /** @var User $actor */
        $actor = $this->getUser();
        $invite = $this->invites->findByToken($token);
        if ($invite === null || !$invite->isUsable()) {
            return $this->gone();
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('circle_join_'.$token, (string) $request->request->get('_token'))) {
                throw new AccessDeniedHttpException('Недействительный токен');
            }

            try {
                $membership = $this->circles->acceptInvite($actor, $invite);
            } catch (\DomainException $exception) {
                if (!$invite->isUsable()) {
                    return $this->gone();
                }
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToRoute('account_circle_join', ['token' => $token]);
            }

            $this->addFlash('success', $membership->isPendingParent()
                ? 'Вы вступили в кружок «'.$invite->getCircle()->getTitle().'» — ждём подтверждения родителя'
                : 'Добро пожаловать в кружок «'.$invite->getCircle()->getTitle().'»');

            return $this->redirectToRoute('account_circles_feed', ['id' => $invite->getCircle()->getId()]);
        }

        return $this->render('account/wardrobe/circle_join.html.twig', [
            'invite' => $invite,
            'occupied' => $this->memberships->countOccupiedMembers($invite->getCircle()),
        ]);
    }

    // ── Медиа ленты ──────────────────────────────────────────────────────────

    /**
     * Фото вещи из карточки ленты. Авторизация — живое членство в кружке,
     * чья активная гранта содержит вещь этого фото (чек-лист утечек §4.3 спеки
     * шеринга: никакого перебора голых photoId). Прямое переиспользование
     * account_wardrobe_media_* невозможно: тот пускает только семью.
     */
    #[Route('/media/photo/{id}', name: 'account_circles_media_photo', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function media(int $id, StorageInterface $storage): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $photo = $this->em->find(WardrobeItemPhoto::class, $id);
        if (!$photo instanceof WardrobeItemPhoto || $photo->isDeleted()) {
            throw $this->createNotFoundException();
        }
        $item = $photo->getItem();
        if ($item === null || !$this->isPhotoInActorCircles($actor, $item)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse((string) $storage->resolvePath($photo, 'file'));
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function isPhotoInActorCircles(User $actor, WardrobeItem $item): bool
    {
        $itemId = $item->getId();
        $ownerId = $item->getUser()?->getId();

        foreach ($this->memberships->findBy(['user' => $actor, 'status' => WardrobeCircleMember::STATUS_ACTIVE]) as $membership) {
            $circle = $membership->getCircle();
            if ($circle->isDissolved()) {
                continue;
            }
            foreach ($this->shares->findActiveForCircle($circle) as $share) {
                $outfit = $share->getOutfit();
                if ($outfit->getWardrobeOwner()?->getId() !== $ownerId) {
                    continue;
                }
                foreach ($outfit->getItems() as $entry) {
                    if ((int) ($entry['id'] ?? 0) === $itemId) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    // ── Хелперы ──────────────────────────────────────────────────────────────

    /** @return list<array{membership: WardrobeCircleMember, invite: ?WardrobeCircleInvite}> */
    private function visibleMemberships(User $actor): array
    {
        $result = [];
        foreach ($this->memberships->findBy(['user' => $actor], ['id' => 'DESC']) as $membership) {
            if (!in_array($membership->getStatus(), WardrobeCircleMember::CAP_STATUSES, true)) {
                continue;
            }
            if ($membership->getCircle()->isDissolved()) {
                continue;
            }
            $invite = in_array($membership->getRole(), [WardrobeCircleMember::ROLE_OWNER, WardrobeCircleMember::ROLE_MODERATOR], true)
                ? $this->invites->findActiveForCircle($membership->getCircle())[0] ?? null
                : null;
            $result[] = ['membership' => $membership, 'invite' => $invite];
        }

        return $result;
    }

    /** @return list<array{category:?string,color:?string,coverPhotoId:?int}> */
    private function feedItems(\App\Entity\WardrobeOutfitShare $share): array
    {
        $ownerId = $share->getOutfit()->getWardrobeOwner()?->getId();
        $snapshotIds = [];
        foreach ($share->getOutfit()->getItems() as $entry) {
            if (isset($entry['id'])) {
                $snapshotIds[(int) $entry['id']] = $entry; // ключ = id: дедупликация снапшота
            }
        }
        if ($snapshotIds === [] || $ownerId === null) {
            return [];
        }

        $photos = $this->em->getRepository(WardrobeItemPhoto::class)->findBy(['id' => array_keys($snapshotIds)]);
        $coverByItemId = [];
        foreach ($photos as $photo) {
            $itemId = $photo->getItem()?->getId();
            if (!$photo->isDeleted() && $itemId !== null && $photo->getItem()?->getUser()?->getId() === $ownerId) {
                $coverByItemId[$itemId] ??= $photo->getId(); // первая (минимальный id) как обложка
            }
        }

        $cards = [];
        foreach ($snapshotIds as $itemId => $entry) {
            $cards[] = [
                'category' => isset($entry['category']) ? (string) $entry['category'] : null,
                'color' => isset($entry['color']) ? (string) $entry['color'] : null,
                'coverPhotoId' => $coverByItemId[$itemId] ?? null,
            ];
        }

        return $cards;
    }

    /** Нейтральный 410 + no-store: без утечки факта существования кружка (§1.4). */
    private function gone(): Response
    {
        $response = $this->render('look/gone.html.twig', [], new Response('', Response::HTTP_GONE));
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    private function limiterKey(Request $request, string $token): string
    {
        return ($request->getClientIp() ?? 'unknown').'|'.$token;
    }
}
