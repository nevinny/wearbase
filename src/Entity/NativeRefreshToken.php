<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NativeRefreshTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NativeRefreshTokenRepository::class)]
#[ORM\Table(name: 'native_refresh_token')]
class NativeRefreshToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'refreshTokens')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private NativeDeviceSession $session;

    #[ORM\Column(name: 'token_hash', length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(NativeDeviceSession $session, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->session = $session;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSession(): NativeDeviceSession { return $this->session; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function isUsed(): bool { return $this->usedAt !== null; }
    public function markUsed(): void { $this->usedAt ??= new \DateTimeImmutable(); }
}
