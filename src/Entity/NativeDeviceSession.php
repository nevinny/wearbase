<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NativeDeviceSessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NativeDeviceSessionRepository::class)]
#[ORM\Table(name: 'native_device_session')]
#[ORM\Index(name: 'idx_native_session_user_device', columns: ['user_id', 'device_hash'])]
class NativeDeviceSession
{
    public const LABEL_OTHER = 'other';
    public const LABELS = ['iphone', 'ipad', 'mac', 'android', self::LABEL_OTHER];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'public_id', length: 32, unique: true)]
    private string $publicId;

    #[ORM\Column(name: 'device_label', length: 16, options: ['default' => self::LABEL_OTHER])]
    private string $deviceLabel;

    #[ORM\Column(name: 'device_hash', length: 64)]
    private string $deviceHash;

    #[ORM\Column(name: 'access_hash', length: 64, unique: true)]
    private string $accessHash;

    #[ORM\Column]
    private \DateTimeImmutable $accessExpiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /** @var Collection<int, NativeRefreshToken> */
    #[ORM\OneToMany(targetEntity: NativeRefreshToken::class, mappedBy: 'session', cascade: ['persist'], orphanRemoval: true)]
    private Collection $refreshTokens;

    public function __construct(User $user, string $deviceHash, string $accessHash, \DateTimeImmutable $accessExpiresAt, string $deviceLabel = self::LABEL_OTHER)
    {
        if (!in_array($deviceLabel, self::LABELS, true)) {
            throw new \InvalidArgumentException('Unknown device label');
        }
        $this->user = $user;
        $this->publicId = bin2hex(random_bytes(16));
        $this->deviceLabel = $deviceLabel;
        $this->deviceHash = $deviceHash;
        $this->accessHash = $accessHash;
        $this->accessExpiresAt = $accessExpiresAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->refreshTokens = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getPublicId(): string { return $this->publicId; }
    public function getDeviceLabel(): string { return $this->deviceLabel; }
    public function getDeviceHash(): string { return $this->deviceHash; }
    public function getAccessHash(): string { return $this->accessHash; }
    public function getAccessExpiresAt(): \DateTimeImmutable { return $this->accessExpiresAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastUsedAt(): ?\DateTimeImmutable { return $this->lastUsedAt; }
    public function isRevoked(): bool { return $this->revokedAt !== null; }

    public function rotateAccess(string $accessHash, \DateTimeImmutable $expiresAt): void
    {
        $this->accessHash = $accessHash;
        $this->accessExpiresAt = $expiresAt;
    }

    public function revoke(): void { $this->revokedAt ??= new \DateTimeImmutable(); }

    public function touch(?\DateTimeImmutable $at = null): void { $this->lastUsedAt = $at ?? new \DateTimeImmutable(); }

    public function addRefreshToken(NativeRefreshToken $token): void
    {
        $this->refreshTokens->add($token);
    }
}
