<?php

declare(strict_types=1);

namespace App\Service\Referral;

use App\Entity\ReferralEvent;
use App\Entity\ReferralRewardGrant;
use App\Entity\User;
use App\Repository\ReferralEventRepository;
use App\Repository\ReferralRewardGrantRepository;
use App\Repository\WardrobeItemRepository;
use App\Repository\WardrobeOutfitRepository;
use App\Repository\WardrobeOutfitShareRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Экономика реферальной программы (спец §1–§4, решения PO): ledger наград поверх
 * атрибуции referral_event. Фрод-контроль живёт здесь, а не в атрибуции —
 * событие остаётся честным фактом, платим или нет, решает этот сервис.
 *
 * Идемпотентность всех грантов через детерминированные idempotency_key:
 * повторный вызов — no-op, сбой между flush'ами безопасен.
 */
final class ReferralRewardService
{
    /** Окно на первое действие после регистрации (решение PO №1). */
    private const QUALIFICATION_WINDOW_DAYS = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReferralRewardGrantRepository $grants,
        private readonly ReferralEventRepository $events,
        private readonly WardrobeItemRepository $items,
        private readonly WardrobeOutfitRepository $outfits,
        private readonly WardrobeOutfitShareRepository $shares,
    ) {}

    /**
     * Welcome приглашённому (решение PO №2): +10 подсказок/день на 30 дней сразу
     * при регистрации + бейдж «По совету подруги». Managed-дети и одноразовые
     * домены награды не получают (спец §3).
     */
    public function grantOnWelcome(ReferralEvent $event): void
    {
        $invitee = $event->getInvitee();
        if ($this->isBlockedAccount($invitee) || $this->isBlockedAccount($event->getInviter())) {
            return;
        }
        if (DisposableEmailDomains::isDisposable($invitee->getEmail())) {
            return;
        }

        $this->grantOnce(new ReferralRewardGrant(
            $invitee,
            ReferralRewardGrant::ROLE_INVITEE,
            ReferralRewardGrant::KIND_AI_QUOTA_BUMP,
            ReferralRewardGrant::WELCOME_DAILY_BUMP,
            sprintf('ref:%d:invitee:welcome', $event->getId()),
            'welcome',
            $event,
            (new \DateTimeImmutable())->modify(sprintf('+%d days', ReferralRewardGrant::WELCOME_DAYS)),
        ));
        $this->grantOnce(new ReferralRewardGrant(
            $invitee,
            ReferralRewardGrant::ROLE_INVITEE,
            ReferralRewardGrant::KIND_BADGE,
            0,
            sprintf('ref:%d:invitee:badge', $event->getId()),
            'welcome_badge',
            $event,
        ));

        $this->em->flush();
    }

    /**
     * Квалификация события для приглашающей (решение PO №1): полный бар «email подтверждён
     * И ≥1 вещь/образ за 30 дней после регистрации». Месячный кап 5 оплачиваемых квалификаций,
     * потолок суммы активных бонусов ≤+30/день, триггеры ревью ≥8 событий/24ч или ≥3 флагов.
     *
     * @return string исход для команды/лога: granted|review|already|managed|disposable|bar_not_met|capped|ceiling
     */
    public function qualifyAndGrant(ReferralEvent $event): string
    {
        $inviter = $event->getInviter();
        $invitee = $event->getInvitee();

        $key = sprintf('ref:%d:inviter:bump', $event->getId());
        if ($this->grants->existsByIdempotencyKey($key)) {
            return 'already';
        }
        if ($this->isBlockedAccount($inviter) || $this->isBlockedAccount($invitee)) {
            return 'managed';
        }
        if (DisposableEmailDomains::isDisposable($invitee->getEmail())) {
            return 'disposable';
        }
        // Полный бар: подтверждённый email + первое действие за 30 дней после регистрации.
        if (!$invitee->isEmailVerified() || !$this->hasActionInWindow($invitee, $event->getCreatedAt())) {
            return 'bar_not_met';
        }

        // Триггеры антифрода: грант выдаётся, но уходит в очередь ручной проверки.
        $status = $this->shouldReview($inviter)
            ? ReferralRewardGrant::STATUS_REVIEW
            : ReferralRewardGrant::STATUS_ACTIVE;

        // Месячный кап: первые N квалификаций месяца оплачиваются, остальные только считаются.
        $monthStart = new \DateTimeImmutable(date('Y-m').'-01T00:00:00');
        if ($this->grants->countInviterBumpsBetween($inviter, $monthStart, $monthStart->modify('+1 month'))
            >= ReferralRewardGrant::MONTHLY_QUALIFIED_CAP) {
            return 'capped';
        }

        // Потолок активных бонусов ≤+30/день: выдаём сколько влезает, при нуле — не выдаём.
        $headroom = ReferralRewardGrant::DAILY_BUMP_CEILING - $this->grants->sumActiveDailyBump($inviter);
        $amount = min(ReferralRewardGrant::INVITER_DAILY_BUMP, $headroom);
        if ($amount <= 0) {
            return 'ceiling';
        }

        $this->grantOnce(new ReferralRewardGrant(
            $inviter,
            ReferralRewardGrant::ROLE_INVITER,
            ReferralRewardGrant::KIND_AI_QUOTA_BUMP,
            $amount,
            $key,
            'bump',
            $event,
            (new \DateTimeImmutable())->modify(sprintf('+%d days', ReferralRewardGrant::INVITER_DAYS)),
            $status,
        ));
        $this->em->flush();

        return $status === ReferralRewardGrant::STATUS_REVIEW ? 'review' : 'granted';
    }

    /** Перевод истёкших грантов active → expired (cron app:referral:expire-grants). */
    public function expireDueGrants(): int
    {
        return $this->grants->expireDue(new \DateTimeImmutable());
    }

    /**
     * Сводка блока «Приглашай подруг» дашборда (спец §4). Заодно лениво фиксирует
     * бейджи-тиры приглашающей (3/10 друзей) — запись при первом достижении порога.
     *
     * @return array{shareToken:?string, invitedCount:int, dailyBonus:int, bonusUntil:?\DateTimeImmutable, badgeNext:?array{current:int,target:int}, badgeEarned:list<int>}
     */
    public function dashboardSummary(User $user): array
    {
        $invited = $this->events->findAllForInviter($user);
        $active = $this->grants->findActiveByUser($user);

        $dailyBonus = 0;
        $bonusUntil = null;
        foreach ($active as $grant) {
            if ($grant->getKind() !== ReferralRewardGrant::KIND_AI_QUOTA_BUMP || $grant->getAmount() <= 0) {
                continue;
            }
            $dailyBonus += $grant->getAmount();
            if ($grant->getExpiresAt() !== null && ($bonusUntil === null || $grant->getExpiresAt() > $bonusUntil)) {
                $bonusUntil = $grant->getExpiresAt();
            }
        }

        $earnedTiers = [];
        $nextTier = null;
        foreach (ReferralRewardGrant::BADGE_TIERS as $tier) {
            if (count($invited) < $tier) {
                $nextTier ??= ['current' => count($invited), 'target' => $tier];
                continue;
            }
            $earnedTiers[] = $tier;
            $this->grantOnce(new ReferralRewardGrant(
                $user,
                ReferralRewardGrant::ROLE_INVITER,
                ReferralRewardGrant::KIND_BADGE,
                0,
                sprintf('ref:user:%d:inviter:tier:%d', $user->getId(), $tier),
                sprintf('tier_%d_friends', $tier),
            ));
        }
        if ($earnedTiers !== []) {
            $this->em->flush();
        }

        $latestShare = $this->shares->findLatestViewableByCreator($user);

        return [
            'shareToken' => $latestShare?->getToken(),
            'invitedCount' => count($invited),
            'dailyBonus' => $dailyBonus,
            'bonusUntil' => $bonusUntil,
            'badgeNext' => $nextTier,
            'badgeEarned' => $earnedTiers,
        ];
    }

    /** Вставка ровно один раз: ключ уже в ledger — повтор no-op. */
    private function grantOnce(ReferralRewardGrant $grant): void
    {
        if ($this->grants->existsByIdempotencyKey($grant->getIdempotencyKey())) {
            return;
        }
        $this->em->persist($grant);
    }

    /** Managed-аккаунты (бесплатные детские профили) не могут ни платить, ни получать награды. */
    private function isBlockedAccount(User $user): bool
    {
        return $user->isManaged();
    }

    /** Первое действие (вещь или образ) в окне [registeredAt, registeredAt+30д). */
    private function hasActionInWindow(User $user, \DateTimeImmutable $registeredAt): bool
    {
        $windowEnd = $registeredAt->modify(sprintf('+%d days', self::QUALIFICATION_WINDOW_DAYS));

        return $this->items->countCreatedByUserBetween($user, $registeredAt, $windowEnd) > 0
            || $this->outfits->countCreatedByUserBetween($user, $registeredAt, $windowEnd) > 0;
    }

    /** Есть ли у пользователя вообще какое-то действие (для кластер-флага). */
    private function hasAnyAction(User $user): bool
    {
        return $this->items->countCreatedByUserBetween($user, new \DateTimeImmutable('@0'), new \DateTimeImmutable())
            + $this->outfits->countCreatedByUserBetween($user, new \DateTimeImmutable('@0'), new \DateTimeImmutable()) > 0;
    }

    /**
     * Триггеры очереди ручной проверки (решение PO №5): ≥8 событий inviter за 24ч
     * ИЛИ ≥3 флагов «приглашённый без единого действия». Автоблокировки нет — только флаг.
     */
    private function shouldReview(User $inviter): bool
    {
        if ($this->events->countForInviterSince($inviter, new \DateTimeImmutable('-24 hours'))
            >= ReferralRewardGrant::REVIEW_EVENTS_PER_24H) {
            return true;
        }

        $flags = 0;
        foreach ($this->events->findAllForInviter($inviter) as $sibling) {
            if (!$sibling->getInvitee()->isEmailVerified() && !$this->hasAnyAction($sibling->getInvitee())) {
                ++$flags;
                if ($flags >= ReferralRewardGrant::REVIEW_FLAGS) {
                    return true;
                }
            }
        }

        return false;
    }
}
