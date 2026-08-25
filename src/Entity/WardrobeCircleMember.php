<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeCircleMemberRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Членство в кружке (docs/circles-spec.md §1): пользователь ↔ кружок + роль + статус.
 *
 * Статусы: active | pending_parent | left | kicked.
 * pending_parent — подросток (FAMILY_ROLE_CHILD, не managed) присоединился сам,
 * без аппрува родителя лента недоступна (зеркало решения №3 по шерингу).
 * Роль moderator вводится, в MVP никому не выдаётся (решение PO №4).
 */
#[ORM\Entity(repositoryClass: WardrobeCircleMemberRepository::class)]
#[ORM\Table(name: 'wardrobe_circle_member')]
#[ORM\UniqueConstraint(name: 'uniq_circle_member', columns: ['circle_id', 'user_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_circle_member_user')]
class WardrobeCircleMember
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_MODERATOR = 'moderator';
    public const ROLE_MEMBER = 'member';
    public const ROLES = [self::ROLE_OWNER, self::ROLE_MODERATOR, self::ROLE_MEMBER];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING_PARENT = 'pending_parent';
    public const STATUS_LEFT = 'left';
    public const STATUS_KICKED = 'kicked';
    /** Статусы, занимающие слот капа MEMBER_CAP. */
    public const CAP_STATUSES = [self::STATUS_ACTIVE, self::STATUS_PENDING_PARENT];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WardrobeCircle $circle;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16)]
    private string $role = self::ROLE_MEMBER;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(WardrobeCircle $circle, User $user, string $role = self::ROLE_MEMBER, string $status = self::STATUS_ACTIVE)
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Недопустимая роль в кружке: '.$role);
        }
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_PENDING_PARENT], true)) {
            throw new \InvalidArgumentException('Начальный статус членства: active или pending_parent');
        }
        $this->circle = $circle;
        $this->user = $user;
        $this->role = $role;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCircle(): WardrobeCircle { return $this->circle; }
    public function getUser(): User { return $this->user; }
    public function getRole(): string { return $this->role; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isPendingParent(): bool { return $this->status === self::STATUS_PENDING_PARENT; }
    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }

    public function approve(): void
    {
        if ($this->status !== self::STATUS_PENDING_PARENT) {
            throw new \DomainException('Подтвердить можно только членство в ожидании родителя');
        }
        $this->status = self::STATUS_ACTIVE;
    }

    /** leave/kick: мгновенная потеря доступа, строка остаётся (uniq circle+user). */
    public function markLeft(): void { $this->status = self::STATUS_LEFT; }
    public function markKicked(): void { $this->status = self::STATUS_KICKED; }

    /** Повторный join по инвайту после выхода/кика: членство реактивируется. */
    public function reactivate(string $status = self::STATUS_ACTIVE): void
    {
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_PENDING_PARENT], true)) {
            throw new \InvalidArgumentException('Реактивация: active или pending_parent');
        }
        $this->status = $status;
    }
}
