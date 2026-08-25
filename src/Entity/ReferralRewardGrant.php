<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReferralRewardGrantRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ledger реферальных наград (спец «Реферальная программа» §6): append-only запись
 * о выданном бонусе — бамп AI-квоты или косметический бейдж. Идемпотентность через
 * детерминированный idempotency_key (повторный grant — no-op), экономические параметры —
 * константыми здесь, не хардкод в сервисах. Фрод-статусы: review (ручная проверка),
 * revoked (тихий отзыв админом); истёкшие переводит app:referral:expire-grants.
 */
#[ORM\Entity(repositoryClass: ReferralRewardGrantRepository::class)]
#[ORM\Table(name: 'referral_reward_ledger')]
#[ORM\Index(columns: ['user_id'], name: 'idx_reward_user')]
#[ORM\UniqueConstraint(name: 'uniq_reward_idem', columns: ['idempotency_key'])]
class ReferralRewardGrant
{
    public const ROLE_INVITER = 'inviter';
    public const ROLE_INVITEE = 'invitee';

    public const KIND_AI_QUOTA_BUMP = 'ai_quota_bump';
    public const KIND_BADGE = 'badge';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVIEW = 'review';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    /** Welcome приглашённому: +10 подсказок/день на 30 дней (решение PO №2). */
    public const WELCOME_DAILY_BUMP = 10;
    public const WELCOME_DAYS = 30;

    /** Награда приглашающей за квалифицированного друга: +5/день на 14 дней. */
    public const INVITER_DAILY_BUMP = 5;
    public const INVITER_DAYS = 14;

    /** Месячный кап: оплачиваются первые N квалификаций календарного месяца. */
    public const MONTHLY_QUALIFIED_CAP = 5;

    /** Потолок суммы активных бонусов на пользователя (эффективный лимит ≤60/день). */
    public const DAILY_BUMP_CEILING = 30;

    /** Пороги очереди ручной проверки (решение PO №5). */
    public const REVIEW_EVENTS_PER_24H = 8;
    public const REVIEW_FLAGS = 3;

    /** Тиры бейджей приглашающей по числу квалифицированных друзей (спец §1). */
    public const BADGE_TIERS = [3, 10];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    /** Кто получает: пригласившая или приглашённая. */
    #[ORM\Column(length: 16)]
    private string $role;

    #[ORM\Column(length: 32)]
    private string $kind;

    /** Величина дневного бампа AI-квоты (для badge = 0). */
    #[ORM\Column(options: ['default' => 0])]
    private int $amount = 0;

    /** Детерминированный ключ идемпотентности, напр. ref:{eventId}:inviter:bump. */
    #[ORM\Column(length: 80)]
    private string $idempotencyKey;

    /** Связь с фактом атрибуции; SET NULL — переживает удаление события. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ReferralEvent $referralEvent = null;

    #[ORM\Column(length: 64)]
    private string $reason;

    #[ORM\Column(length: 16, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $grantedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct(
        User $user,
        string $role,
        string $kind,
        int $amount,
        string $idempotencyKey,
        string $reason,
        ?ReferralEvent $referralEvent = null,
        ?\DateTimeImmutable $expiresAt = null,
        string $status = self::STATUS_ACTIVE,
    ) {
        $this->user = $user;
        $this->role = $role;
        $this->kind = $kind;
        $this->amount = $amount;
        $this->idempotencyKey = $idempotencyKey;
        $this->reason = $reason;
        $this->referralEvent = $referralEvent;
        $this->grantedAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
        $this->status = $status;
    }
    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getRole(): string { return $this->role; }
    public function getKind(): string { return $this->kind; }
    public function getAmount(): int { return $this->amount; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getReferralEvent(): ?ReferralEvent { return $this->referralEvent; }
    public function getReason(): string { return $this->reason; }
    public function getStatus(): string { return $this->status; }

    public function getGrantedAt(): \DateTimeImmutable { return $this->grantedAt; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }

    /** Админ-операция тихого отзыва при фроде (решение PO №6): статус меняется, строка живёт. */
    public function revoke(): void
    {
        $this->status = self::STATUS_REVOKED;
    }
}
