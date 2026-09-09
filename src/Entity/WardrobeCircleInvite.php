<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeCircleInviteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Приглашение в кружок (docs/circles-spec.md §1.4) — паттерн FamilyInvite:
 * opaque 64-hex токен (256 бит), поиск по уникальному индексу.
 *
 * Многоразовый до экспирации в пределах свободного капа кружка (решение PO №3);
 * повторное «Пригласить» = новый токен (канал-на-ссылку). Истечение/отзыв →
 * нейтральный 410 + no-store.
 */
#[ORM\Entity(repositoryClass: WardrobeCircleInviteRepository::class)]
#[ORM\Table(name: 'wardrobe_circle_invite')]
#[ORM\Index(columns: ['circle_id'], name: 'idx_circle_invite_circle')]
class WardrobeCircleInvite
{
    public const TTL = '+7 days';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WardrobeCircle $circle;

    /** Кто выдал ссылку (owner/moderator). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $createdBy;

    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(WardrobeCircle $circle, User $createdBy)
    {
        // Паттерн репо (BrandInvite/FamilyInvite/WardrobeOutfitShare).
        $this->token = bin2hex(random_bytes(32));
        $this->circle = $circle;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable(self::TTL);
    }

    public function getId(): ?int { return $this->id; }
    public function getCircle(): WardrobeCircle { return $this->circle; }
    public function getCreatedBy(): User { return $this->createdBy; }
    public function getToken(): string { return $this->token; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }

    public function revoke(): void
    {
        $this->revokedAt ??= new \DateTimeImmutable();
    }

    /** Пригоден для акцепта: не отозван, не истёк, кружок жив. */
    public function isUsable(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return $this->revokedAt === null
            && $this->expiresAt > $now
            && !$this->circle->isDissolved();
    }
}
