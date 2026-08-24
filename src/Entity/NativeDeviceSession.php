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
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

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

    /** @var Collection<int, NativeRefreshToken> */
    #[ORM\OneToMany(targetEntity: NativeRefreshToken::class, mappedBy: 'session', cascade: ['persist'], orphanRemoval: true)]
    private Collection $refreshTokens;

    public function __construct(User $user, string $deviceHash, string $accessHash, \DateTimeImmutable $accessExpiresAt)
    {
        $this->user = $user;
        $this->deviceHash = $deviceHash;
        $this->accessHash = $accessHash;
        $this->accessExpiresAt = $accessExpiresAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->refreshTokens = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getDeviceHash(): string { return $this->deviceHash; }
    public function getAccessHash(): string { return $this->accessHash; }
    public function getAccessExpiresAt(): \DateTimeImmutable { return $this->accessExpiresAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function isRevoked(): bool { return $this->revokedAt !== null; }

    public function rotateAccess(string $accessHash, \DateTimeImmutable $expiresAt): void
    {
        $this->accessHash = $accessHash;
        $this->accessExpiresAt = $expiresAt;
    }

    public function revoke(): void { $this->revokedAt ??= new \DateTimeImmutable(); }

    public function addRefreshToken(NativeRefreshToken $token): void
    {
        $this->refreshTokens->add($token);
    }
}
