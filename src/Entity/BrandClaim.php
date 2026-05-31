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

    public const METHOD_EMAIL_CODE  = 'email_code';
    public const METHOD_VK_ADMIN    = 'vk_admin';
    public const METHOD_DOCUMENT    = 'document';
    public const METHOD_MARKETPLACE = 'marketplace';
    public const METHOD_MANUAL      = 'manual';

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

    /** Выбранный метод верификации */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $method = null;

    /** Код подтверждения (email) */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $verificationCode = null;

    /** State/nonce для OAuth (VK) */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $verificationToken = null;

    /** TTL кода */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $codeExpiresAt = null;

    /** Когда код последний раз отправлен (cooldown) */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $codeSentAt = null;

    /** Сколько раз отправляли код (лимит на отправку) */
    #[ORM\Column(options: ['default' => 0])]
    private int $codeSends = 0;

    /** Сколько раз вводили код (защита от перебора) */
    #[ORM\Column(options: ['default' => 0])]
    private int $codeAttempts = 0;

    /** Чем реально подтверждено владение (аудит) */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $verifiedVia = null;

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

    public function getMethod(): ?string { return $this->method; }
    public function setMethod(?string $m): static { $this->method = $m; return $this; }

    public function getVerificationCode(): ?string { return $this->verificationCode; }
    public function setVerificationCode(?string $c): static { $this->verificationCode = $c; return $this; }

    public function getVerificationToken(): ?string { return $this->verificationToken; }
    public function setVerificationToken(?string $t): static { $this->verificationToken = $t; return $this; }

    public function getCodeExpiresAt(): ?\DateTimeImmutable { return $this->codeExpiresAt; }
    public function setCodeExpiresAt(?\DateTimeImmutable $d): static { $this->codeExpiresAt = $d; return $this; }

    public function getCodeSentAt(): ?\DateTimeImmutable { return $this->codeSentAt; }
    public function setCodeSentAt(?\DateTimeImmutable $d): static { $this->codeSentAt = $d; return $this; }

    public function getCodeSends(): int { return $this->codeSends; }
    public function setCodeSends(int $n): static { $this->codeSends = $n; return $this; }

    public function getCodeAttempts(): int { return $this->codeAttempts; }
    public function setCodeAttempts(int $n): static { $this->codeAttempts = $n; return $this; }

    public function getVerifiedVia(): ?string { return $this->verifiedVia; }
    public function setVerifiedVia(?string $v): static { $this->verifiedVia = $v; return $this; }

    public function getAdminNote(): ?string { return $this->adminNote; }
    public function setAdminNote(?string $adminNote): static { $this->adminNote = $adminNote; return $this; }

    public function getReviewedBy(): ?User { return $this->reviewedBy; }
    public function setReviewedBy(?User $u): static { $this->reviewedBy = $u; return $this; }

    public function getReviewedAt(): ?\DateTimeImmutable { return $this->reviewedAt; }
    public function setReviewedAt(?\DateTimeImmutable $d): static { $this->reviewedAt = $d; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
