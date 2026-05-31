<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BrandClaimRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Заявка пользователя на владение существующим брендом.
 *
 * Статусы:
 *  pending           — создана, ждёт действий
 *  email_verified    — домен email совпал с доменом бренда (авто)
 *  approved          — одобрена администратором → создаётся BrandUser owner
 *  rejected          — отклонена
 */
#[ORM\Entity(repositoryClass: BrandClaimRepository::class)]
#[ORM\HasLifecycleCallbacks]
class BrandClaim
{
    public const STATUS_PENDING        = 'pending';
    public const STATUS_EMAIL_VERIFIED = 'email_verified';
    public const STATUS_APPROVED       = 'approved';
    public const STATUS_REJECTED       = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Brand $brand = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    /** Как пользователь может подтвердить владение */
    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    /** Комментарий пользователя: почему он владелец */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    /** Домен email пользователя совпал с доменом email бренда */
    #[ORM\Column(options: ['default' => false])]
    private bool $emailDomainMatch = false;

    /** Комментарий администратора при одобрении/отклонении */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adminNote = null;

    #[ORM\ManyToOne]
    private ?User $reviewedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ── Getters / Setters ─────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getBrand(): ?Brand { return $this->brand; }
    public function setBrand(?Brand $brand): static { $this->brand = $brand; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function isPending(): bool   { return $this->status === self::STATUS_PENDING; }
    public function isApproved(): bool  { return $this->status === self::STATUS_APPROVED; }
    public function isRejected(): bool  { return $this->status === self::STATUS_REJECTED; }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING        => 'На рассмотрении',
            self::STATUS_EMAIL_VERIFIED => 'Email подтверждён',
            self::STATUS_APPROVED       => 'Одобрено',
            self::STATUS_REJECTED       => 'Отклонено',
            default                     => $this->status,
        };
    }

    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): static { $this->comment = $comment; return $this; }

    public function isEmailDomainMatch(): bool { return $this->emailDomainMatch; }
    public function setEmailDomainMatch(bool $v): static { $this->emailDomainMatch = $v; return $this; }

    public function getAdminNote(): ?string { return $this->adminNote; }
    public function setAdminNote(?string $adminNote): static { $this->adminNote = $adminNote; return $this; }

    public function getReviewedBy(): ?User { return $this->reviewedBy; }
    public function setReviewedBy(?User $u): static { $this->reviewedBy = $u; return $this; }

    public function getReviewedAt(): ?\DateTimeImmutable { return $this->reviewedAt; }
    public function setReviewedAt(?\DateTimeImmutable $d): static { $this->reviewedAt = $d; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
