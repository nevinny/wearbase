<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeOutfitShareRepository;
use App\Entity\WardrobeCircle;
use Doctrine\ORM\Mapping as ORM;

/**
 * Гостевая ссылка на лук («Поделиться луком», _docs/outfit-sharing-spec.md §1).
 * Одна строка = одна ссылка (канал-на-ссылку, решение PO №2): повторный «Поделиться»
 * создаёт новую строку, старую можно отозвать точечно. Токен — 64 hex (256 бит),
 * ищется напрямую по уникальному индексу; никаких предсказуемых ID в URL.
 */
#[ORM\Entity(repositoryClass: WardrobeOutfitShareRepository::class)]
#[ORM\Table(name: 'wardrobe_outfit_share')]
#[ORM\Index(columns: ['outfit_id'], name: 'idx_share_outfit')]
#[ORM\Index(columns: ['circle_id'], name: 'idx_share_circle')]
#[ORM\UniqueConstraint(name: 'uniq_share_token', columns: ['token'])]
class WardrobeOutfitShare
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING_PARENT = 'pending_parent';
    public const STATUS_REVOKED = 'revoked';
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_PENDING_PARENT, self::STATUS_REVOKED];

    /** TTL-опции UI (решение PO №1: дефолт 7 дней; «без срока» за feature flag — в MVP не строится). */
    public const TTL_24H = '24h';
    public const TTL_7D = '7d';
    public const TTL_30D = '30d';
    public const TTL_OPTIONS = [
        self::TTL_24H => '+1 day',
        self::TTL_7D => '+7 days',
        self::TTL_30D => '+30 days',
    ];
    public const DEFAULT_TTL = self::TTL_7D;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WardrobeOutfit $outfit;

    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    /** Актор, создавший ссылку: владелец гардероба или родитель ребёнка. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $createdBy;

    /**
     * active — доступна гостям; pending_parent — создана подростком, ждёт подтверждения
     * родителя (решение PO №3); revoked — отозвана владельцем/родителем.
     */
    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING_PARENT;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $ttl = self::DEFAULT_TTL;

    /** Момент подтверждения ссылки (для pending_parent — момент аппрува родителем). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $grantedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /** Внутренний счётчик просмотров без UTM (§6): инкремент после отдачи ответа, боты отсечены. */
    #[ORM\Column(options: ['default' => 0])]
    private int $viewCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastViewedAt = null;

    /**
     * Кружковый грант (docs/circles-spec.md §2): nullable — строка либо гостевая
     * (circle_id IS NULL), либо кружковая (токен генерируется, но никогда не выдаётся).
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?WardrobeCircle $circle = null;

    public function __construct(WardrobeOutfit $outfit, User $createdBy, string $ttl = self::DEFAULT_TTL)
    {
        // 256 бит энтропии — паттерн репо (BrandInvite/FamilyInvite), поиск по уникальному индексу.
        $this->token = bin2hex(random_bytes(32));
        $this->outfit = $outfit;
        $this->createdBy = $createdBy;
        $this->setTtl($ttl);

    }
    public function getId(): ?int { return $this->id; }

    public function getOutfit(): WardrobeOutfit { return $this->outfit; }
    public function getToken(): string { return $this->token; }
    public function getCircle(): ?WardrobeCircle { return $this->circle; }
    public function setCircle(?WardrobeCircle $circle): static { $this->circle = $circle; return $this; }
    public function getCreatedBy(): User { return $this->createdBy; }
    public function getStatus(): string { return $this->status; }
    public function getTtl(): ?string { return $this->ttl; }

    public function setTtl(string $ttl): static
    {
        if (!isset(self::TTL_OPTIONS[$ttl])) {
            throw new \InvalidArgumentException('Недопустимый срок жизни ссылки');
        }
        $this->ttl = $ttl;
        $this->expiresAt = new \DateTimeImmutable(self::TTL_OPTIONS[$ttl]);

        return $this;
    }

    public function getGrantedAt(): ?\DateTimeImmutable { return $this->grantedAt; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function getViewCount(): int { return $this->viewCount; }
    public function getLastViewedAt(): ?\DateTimeImmutable { return $this->lastViewedAt; }

    /** Родитель подтвердил ссылку подростка (или владелец сразу активирует): TTL стартует с аппрува. */
    public function approve(): void
    {
        if ($this->status === self::STATUS_REVOKED) {
            throw new \DomainException('Отозванную ссылку нельзя подтвердить');
        }
        $this->status = self::STATUS_ACTIVE;
        $this->grantedAt = new \DateTimeImmutable();
        $this->expiresAt = $this->ttl !== null
            ? $this->grantedAt->modify(self::TTL_OPTIONS[$this->ttl])
            : null;
    }

    public function revoke(): void
    {
        $this->status = self::STATUS_REVOKED;
        $this->revokedAt = new \DateTimeImmutable();
    }

    /**
     * Гостевая страница доступна только для active и неистёкшей ГОСТЕВОЙ ссылки
     * (§1.4, решение PO №3). Кружковый грант гостевого доступа не даёт никогда:
     * его токен генерируется, но не выдаётся (§2 спеки кружков).
     */
    public function isViewable(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return $this->status === self::STATUS_ACTIVE
            && $this->circle === null
            && ($this->expiresAt === null || $this->expiresAt > $now);
    }

    public function isPendingParent(): bool { return $this->status === self::STATUS_PENDING_PARENT; }

    public function markViewed(): void
    {
        $this->viewCount++;
        $this->lastViewedAt = new \DateTimeImmutable();
    }
}
