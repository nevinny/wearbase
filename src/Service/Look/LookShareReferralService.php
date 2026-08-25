<?php

declare(strict_types=1);

namespace App\Service\Look;

use App\Entity\ReferralEvent;
use App\Entity\User;
use App\Repository\ReferralEventRepository;
use App\Repository\WardrobeOutfitShareRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Referral-хук «Поделиться луком» (спец §7, решение PO №6): гость приходит по ссылке
 * с ?ref={shareToken}, лендинг держит связку в сессии (look_share_ref), а после
 * успешной регистрации здесь пишется РОВНО ОДНО событие атрибуции. UTM-free:
 * канонический URL чистый, источник — сама share-строка. Награды — вне MVP.
 */
final class LookShareReferralService
{
    public const SESSION_KEY = 'look_share_ref';

    public function __construct(
        private readonly WardrobeOutfitShareRepository $shares,
        private readonly ReferralEventRepository $referrals,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Резолвит look_share_ref из сессии и пишет одно referral_event
     * (inviter = создатель share). Идемпотентно: уникальный индекс
     * uniq_referral_once + удаление ключа из сессии гарантируют «ровно один раз».
     */
    public function recordFromSession(Request $request, User $invitee): void
    {
        $ref = (string) $request->getSession()->remove(self::SESSION_KEY);
        if (preg_match('/^[0-9a-f]{64}$/', $ref) !== 1) {
            return;
        }

        $share = $this->shares->findByToken($ref);
        if ($share === null || !$share->isViewable()) {
            return;
        }

        $inviter = $share->getCreatedBy();
        if ($inviter->getId() === $invitee->getId()) {
            return; // самоприглашение не считаем
        }

        $exists = $this->referrals->count([
            'invitee' => $invitee,
            'source' => ReferralEvent::SOURCE_LOOK_SHARE,
            'shareId' => $share->getId(),
        ]) > 0;
        if ($exists) {
            return;
        }

        $this->em->persist(new ReferralEvent(
            $inviter,
            $invitee,
            ReferralEvent::SOURCE_LOOK_SHARE,
            $share->getId(),
        ));
        $this->em->flush();
    }
}
