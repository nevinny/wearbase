<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BrandInviteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: BrandInviteRepository::class)]
class BrandInvite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Brand $brand = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $invitedBy = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $token = null;

    #[ORM\Column(length: 20, options: ['default' => 'manager'])]
    private string $role = BrandUser::ROLE_MANAGER;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->token = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable('+7 days');
    }

    public function getId(): ?int { return $this->id; }

    public function getBrand(): ?Brand { return $this->brand; }

    public function setBrand(?Brand $brand): static
    {
        $this->brand = $brand;
        return $this;
    }

    public function getInvitedBy(): ?User { return $this->invitedBy; }

    public function setInvitedBy(?User $invitedBy): static
    {
        $this->invitedBy = $invitedBy;
        return $this;
    }

    public function getEmail(): ?string { return $this->email; }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getToken(): ?string { return $this->token; }

    public function getRole(): string { return $this->role; }

    public function setRole(string $role): static
    {
        $this->role = $role;
        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function isExpired(): bool { return $this->expiresAt < new \DateTimeImmutable(); }

    public function isAccepted(): bool { return $this->acceptedAt !== null; }

    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }

    public function setAcceptedAt(?\DateTimeImmutable $acceptedAt): static
    {
        $this->acceptedAt = $acceptedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
