<?php

declare(strict_types=1);

namespace App\Service\Circle;

use App\Entity\User;
use App\Entity\WardrobeCircle;
use App\Entity\WardrobeCircleInvite;
use App\Entity\WardrobeCircleMember;
use App\Entity\WardrobeOutfit;
use App\Entity\WardrobeOutfitShare;
use App\Repository\WardrobeCircleInviteRepository;
use App\Repository\WardrobeCircleMemberRepository;
use App\Repository\WardrobeOutfitShareRepository;
use App\Service\FamilyService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Кружки подруг (docs/circles-spec.md): вся авторизация кружковых операций живёт
 * ЗДЕСЬ, а не в Voter'ах — тот же принцип, что и у FamilyService (сервис
 * переиспользуется вне HTTP-контекста).
 *
 * Инварианты приватности (§3): managed-ребёнок в кружок не вступает вообще;
 * подросток (child, не managed) вступает со статусом pending_parent и без аппрува
 * родителя ленты не видит; выход/кик лишают доступа мгновенно — предикат ленты
 * проверяет живое членство на каждый запрос.
 */
class CircleService
{
    /** Антиспам от фермы приглашений: ≤5 активных кружков на пользователя. */
    public const MAX_CIRCLES_PER_USER = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FamilyService $families,
    ) {}

    // ── Создание/расформирование ────────────────────────────────────────────

    public function create(User $owner, string $title): WardrobeCircle
    {
        $this->assertNotManaged($owner);
        $members = $this->memberRepo();
        if ($members->countOccupiedCircles($owner) >= self::MAX_CIRCLES_PER_USER) {
            throw new \DomainException(sprintf('Можно состоять не более чем в %d кружках', self::MAX_CIRCLES_PER_USER));
        }

        $circle = new WardrobeCircle($owner, $title);
        $membership = new WardrobeCircleMember($circle, $owner, WardrobeCircleMember::ROLE_OWNER);
        $this->em->persist($circle);
        $this->em->persist($membership);
        $this->em->flush();

        return $circle;
    }

    /** Только владелец; живые кружковые гранты кружка отзываются — расформирование = отзыв доступа. */
    public function dissolve(User $actor, WardrobeCircle $circle): void
    {
        $this->assertOwner($actor, $circle);

        foreach ($this->shareRepo()->findActiveForCircle($circle) as $share) {
            $share->revoke();
        }
        $circle->dissolve();
        $this->em->flush();
    }

    // ── Инвайты ──────────────────────────────────────────────────────────────

    public function createInvite(User $actor, WardrobeCircle $circle): WardrobeCircleInvite
    {
        $this->assertCanInvite($actor, $circle);

        $invite = new WardrobeCircleInvite($circle, $actor);
        $this->em->persist($invite);
        $this->em->flush();

        return $invite;
    }

    public function revokeInvite(User $actor, WardrobeCircleInvite $invite): void
    {
        $this->assertCanInvite($actor, $invite->getCircle());
        $invite->revoke();
        $this->em->flush();
    }

    /** Повторное «Пригласить» = новый токен (канал-на-ссылку), старый отзывается. */
    public function renewInvite(User $actor, WardrobeCircleInvite $invite): WardrobeCircleInvite
    {
        $this->assertCanInvite($actor, $invite->getCircle());
        $invite->revoke();

        return $this->createInvite($actor, $invite->getCircle());
    }

    /**
     * Акцепт инвайта залогиненным пользователем. Транзакция с пессимистичной
     * блокировкой (паттерн FamilyService::acceptInvite): капы проверяются под локом.
     *
     * @throws \DomainException ссылка непригодна (истекла/отозвана/кружок распущен),
     *                         пользователь managed, капы исчерпаны или уже участник
     */
    public function acceptInvite(User $user, WardrobeCircleInvite $invite): WardrobeCircleMember
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $lockedInvite = $this->em->find(WardrobeCircleInvite::class, $invite->getId(), LockMode::PESSIMISTIC_WRITE);
            $lockedUser = $this->em->find(User::class, $user->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$lockedInvite instanceof WardrobeCircleInvite || !$lockedUser instanceof User) {
                throw new \DomainException('Ссылка больше не действует');
            }
            $this->em->refresh($lockedInvite, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($lockedUser, LockMode::PESSIMISTIC_WRITE);

            if (!$lockedInvite->isUsable()) {
                throw new \DomainException('Ссылка истекла или была отозвана');
            }
            $circle = $lockedInvite->getCircle();
            $members = $this->memberRepo();

            $this->assertNotManaged($lockedUser);
            if ($lockedUser->getId() !== $circle->getOwner()->getId()) {
                if ($members->countOccupiedCircles($lockedUser) >= self::MAX_CIRCLES_PER_USER) {
                    throw new \DomainException(sprintf('Можно состоять не более чем в %d кружках', self::MAX_CIRCLES_PER_USER));
                }
            }
            if ($members->countOccupiedMembers($circle) >= WardrobeCircle::MEMBER_CAP) {
                throw new \DomainException(sprintf('В кружке уже максимум участников (%d)', WardrobeCircle::MEMBER_CAP));
            }

            $status = $this->initialStatusFor($lockedUser);
            $membership = $members->findOneBy(['circle' => $circle, 'user' => $lockedUser]);
            if ($membership !== null) {
                if (in_array($membership->getStatus(), WardrobeCircleMember::CAP_STATUSES, true)) {
                    throw new \DomainException('Вы уже состоите в этом кружке');
                }
                $membership->reactivate($status);
            } else {
                $membership = new WardrobeCircleMember($circle, $lockedUser, status: $status);
                $this->em->persist($membership);
            }

            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $exception;
        }

        return $membership;
    }

    /**
     * Родитель подтверждает/отклоняет членство подростка (pending_parent):
     * guard через FamilyService::canManage — как parent-confirm у шеринга луков.
     */
    public function confirmMembership(User $parent, WardrobeCircleMember $membership, bool $approve): void
    {
        if (!$this->families->canManage($parent, $membership->getUser())) {
            throw new AccessDeniedException('Подтвердить членство ребёнка может только родитель');
        }

        if ($approve) {
            if ($this->memberRepo()->countOccupiedMembers($membership->getCircle()) >= WardrobeCircle::MEMBER_CAP) {
                throw new \DomainException(sprintf('В кружке уже максимум участников (%d)', WardrobeCircle::MEMBER_CAP));
            }
            $membership->approve();
        } else {
            $membership->markLeft();
        }
        $this->em->flush();
    }

    // ── Выход/кик ────────────────────────────────────────────────────────────

    /**
     * Выход из кружка: мгновенная потеря доступа к ленте. Владелец обязан передать
     * владение преемнику ДО выхода (решение PO №5) — иначе отказ с объяснением.
     */
    public function leave(User $actor, WardrobeCircle $circle, ?int $successorId = null): void
    {
        $membership = $this->requireMembership($actor, $circle);
        if ($membership->getRole() === WardrobeCircleMember::ROLE_OWNER) {
            if ($successorId === null) {
                throw new \DomainException('Вы владелец кружка: выберите преемника, прежде чем выйти');
            }
            $successor = $this->em->find(WardrobeCircleMember::class, $successorId);
            if (!$successor instanceof WardrobeCircleMember
                || $successor->getCircle()->getId() !== $circle->getId()
                || !$successor->isActive()
                || $successor->getUser()->getId() === $actor->getId()
            ) {
                throw new \DomainException('Преемником может быть только активный участник кружка');
            }
            $circle->setOwner($successor->getUser());
            $successor->promoteToOwner();
            $membership->demoteFromOwner();
        }

        $membership->markLeft();
        $this->em->flush();
    }

    /** Кик: owner или moderator (роль в MVP никому не выдаётся, механика готова). */
    public function kick(User $actor, WardrobeCircle $circle, int $memberId): void
    {
        $actorMembership = $this->requireMembership($actor, $circle);
        if (!in_array($actorMembership->getRole(), [WardrobeCircleMember::ROLE_OWNER, WardrobeCircleMember::ROLE_MODERATOR], true)) {
            throw new AccessDeniedException('Исключать участников может только владелец');
        }

        $target = $this->em->find(WardrobeCircleMember::class, $memberId);
        if (!$target instanceof WardrobeCircleMember || $target->getCircle()->getId() !== $circle->getId()) {
            throw new \DomainException('Участник не найден в этом кружке');
        }
        if ($target->getUser()->getId() === $actor->getId() || $target->getRole() === WardrobeCircleMember::ROLE_OWNER) {
            throw new \DomainException('Владельца исключить нельзя');
        }
        if (!in_array($target->getStatus(), WardrobeCircleMember::CAP_STATUSES, true)) {
            throw new \DomainException('Участник уже не в кружке');
        }

        $target->markKicked();
        $this->em->flush();
    }

    // ── Лента ────────────────────────────────────────────────────────────────

    /** Живое членство (active) на каждый запрос: выход/кик лишают доступа мгновенно (§3.3). */
    public function canViewFeed(User $actor, WardrobeCircle $circle): bool
    {
        return $this->memberRepo()->findActive($actor, $circle) !== null;
    }

    public function requireFeedAccess(User $actor, WardrobeCircle $circle): void
    {
        if (!$this->canViewFeed($actor, $circle)) {
            throw new AccessDeniedException('Нет доступа к ленте этого кружка');
        }
    }

    // ── Шеринг лука в кружок ────────────────────────────────────────────────
    /**
     * Кружковый грант: та же строка wardrobe_outfit_share, circle_id заполнен,
     * токен генерируется, но никогда не выдаётся (§2). Статус по правилам детей:
     * подросток шарит свой лук сам → pending_parent (двойной opt-in, решение PO №3
     * по шерингу); взрослый за себя или родитель за ребёнка → сразу активен.
     */
    public function shareToCircle(User $actor, WardrobeOutfit $outfit, WardrobeCircle $circle, string $ttl = WardrobeOutfitShare::DEFAULT_TTL): WardrobeOutfitShare
    {
        $this->requireFeedAccess($actor, $circle);
        $owner = $outfit->getWardrobeOwner();
        if ($outfit->getUser()?->getId() !== $actor->getId()
            && ($owner === null || !$this->families->canManage($actor, $owner))) {
            throw new AccessDeniedException('Делиться можно только своими луками');
        }

        // Одна строка = один грант: токен генерируется, но кружку не выдаётся.
        $share = new WardrobeOutfitShare($outfit, $actor, $ttl);
        $share->setCircle($circle);
        if (!($actor->getId() === $outfit->getWardrobeOwner()?->getId() && $actor->getFamilyRole() === User::FAMILY_ROLE_CHILD)) {
            $share->approve();
        }
        $this->em->persist($share);
        $this->em->flush();

        return $share;
    }

    /**
     * Отзыв родительского согласия = массовый revoke кружковых share детских луков
     * (§3.1, зеркало «Отзыв согласия на делиться детскими луками» из спеки шеринга).
     *
     * @return int сколько грантов отозвано
     */
    public function revokeChildCircleShares(User $parent, User $child): int
    {
        if ($child->getFamilyRole() !== User::FAMILY_ROLE_CHILD || !$this->families->canManage($parent, $child)) {
            throw new AccessDeniedException('Отозвать согласие может только управляющий родитель');
        }

        $shares = $this->shareRepo()->findLiveForAuthor($child);
        foreach ($shares as $share) {
            $share->revoke();
        }
        $this->em->flush();

        return count($shares);
    }

    // ── Хелперы ──────────────────────────────────────────────────────────────

    private function initialStatusFor(User $user): string
    {
        // Подросток с собственным входом: членство ждёт аппрува родителя (§3.1).
        return $user->getFamilyRole() === User::FAMILY_ROLE_CHILD && !$user->isManaged()
            ? WardrobeCircleMember::STATUS_PENDING_PARENT
            : WardrobeCircleMember::STATUS_ACTIVE;
    }

    private function requireMembership(User $user, WardrobeCircle $circle): WardrobeCircleMember
    {
        $membership = $this->memberRepo()->findOneBy(['circle' => $circle, 'user' => $user]);
        if ($membership === null || !in_array($membership->getStatus(), WardrobeCircleMember::CAP_STATUSES, true)) {
            throw new AccessDeniedException('Вы не участник этого кружка');
        }

        return $membership;
    }

    private function assertCanInvite(User $actor, WardrobeCircle $circle): void
    {
        $this->assertAlive($circle);
        $membership = $this->requireMembership($actor, $circle);
        if (!in_array($membership->getRole(), [WardrobeCircleMember::ROLE_OWNER, WardrobeCircleMember::ROLE_MODERATOR], true)) {
            throw new AccessDeniedException('Приглашать может только владелец кружка');
        }
    }

    private function assertOwner(User $actor, WardrobeCircle $circle): void
    {
        $this->assertAlive($circle);
        if ($circle->getOwner()->getId() !== $actor->getId()) {
            throw new AccessDeniedException('Расформировать кружок может только владелец');
        }
    }

    private function assertAlive(WardrobeCircle $circle): void
    {
        if ($circle->isDissolved()) {
            throw new \DomainException('Кружок расформирован');
        }
    }

    private function assertNotManaged(User $user): void
    {
        if ($user->isManaged()) {
            // Луками managed-ребёнка в кружках распоряжается родитель (§3.1).
            throw new AccessDeniedException('Профиль ребёнка под родительским управлением не может участвовать в кружках');
        }
    }

    private function memberRepo(): WardrobeCircleMemberRepository
    {
        return $this->em->getRepository(WardrobeCircleMember::class);
    }

    private function shareRepo(): WardrobeOutfitShareRepository
    {
        return $this->em->getRepository(WardrobeOutfitShare::class);
    }
}
