<?php

declare(strict_types=1);

namespace App\Service\Circle;

use App\Entity\User;
use App\Entity\WardrobeCircleMember;
use App\Entity\WardrobeOutfitShare;
use App\Entity\WardrobeShareReaction;
use App\Repository\WardrobeShareReactionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Реакции «огонь» в кружке (docs/ratings-spec.md): вся антиабьюз-логика ЗДЕСЬ,
 * а не в Voter'ах — тот же принцип, что у CircleService (§4 спеки рейтингов).
 *
 * Инварианты:
 *  - нельзя себе: share.outfit.user === actor ИЛИ share.createdBy === actor → отказ
 *    (self-feedback владельца живёт в WardrobeOutfit.reaction и это другое);
 *  - только active-член именно этого кружка: живое членство читается на каждый
 *    запрос — вышедший/кикнутый теряет кнопку мгновенно, прошлые реакции остаются;
 *  - идемпотентность: повторный POST → 200 + текущее состояние; гонка двух
 *    параллельных запросов гасится uniq (share_id, member_id) с catch
 *    UniqueConstraintViolationException как успехом (образец uniq_referral_once);
 *  - positive-only: дизлайка нет по построению (решение PO №1).
 */
class CircleReactionService
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
    ) {}

    /**
     * Поставить «огонь» на кружковый share от имени actor.
     *
     * @return array{reacted: bool, count: int} reacted всегда true — «забрать огонь
     *                                           назад» не строим (§3.4); count — текущая сумма по share
     *
     * @throws \DomainException грант не найден / чужой кружок / не живой (→ нейтральный 404)
     * @throws AccessDeniedException реакция на свой лук или нет активного членства
     */
    public function react(User $actor, int $circleId, int $shareId): array
    {
        $em = $this->doctrine->getManager();
        $share = $em->find(WardrobeOutfitShare::class, $shareId);

        // Нейтральный отказ без деталей: не существует, другой кружок или грант
        // истёк/отозван/ждёт родителя (решение PO №5 — по мёртвому гранту кнопки нет).
        if (!$share instanceof WardrobeOutfitShare
            || $share->getCircle()?->getId() !== $circleId
            || !$this->isLive($share)
        ) {
            throw new \DomainException('Грант недоступен');
        }

        // §4.1: оба пути авторства лука закрывают самореакцию.
        if ($share->getOutfit()->getUser()?->getId() === $actor->getId()
            || $share->getCreatedBy()->getId() === $actor->getId()
        ) {
            throw new AccessDeniedException('На свои луки реагировать нельзя');
        }

        // §4.2: предикат читает живое членство на каждый запрос (leave/kick = мгновенно).
        $membership = $em->getRepository(WardrobeCircleMember::class)->findActive($actor, $share->getCircle());
        if ($membership === null) {
            throw new AccessDeniedException('Реагировать могут только активные участники кружка');
        }

        try {
            $em->persist(new WardrobeShareReaction($share, $membership));
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            // §4.4: проигравшая гонка уже вставила пару — это тот же успех.
            // EM после нарушения unique закрыт — сбрасываем, дальше только чтение.
            $this->doctrine->resetManager();
        }

        /** @var WardrobeShareReactionRepository $reactions */
        $reactions = $this->doctrine->getManager()->getRepository(WardrobeShareReaction::class);

        return ['reacted' => true, 'count' => $reactions->countForShare($shareId)];
    }

    /** Живой грант: active и неистёкший (TTL). Отозванный — не живой. */
    private function isLive(WardrobeOutfitShare $share): bool
    {
        return $share->getStatus() === WardrobeOutfitShare::STATUS_ACTIVE
            && ($share->getExpiresAt() === null || $share->getExpiresAt() > new \DateTimeImmutable());
    }
}
