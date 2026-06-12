<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BrandUserRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BrandUserRepository::class)]
#[ORM\UniqueConstraint(name: 'brand_user_unique', columns: ['brand_id', 'user_id'])]
class BrandUser
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_MANAGER = 'manager';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'brandUsers')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Brand $brand = null;

    #[ORM\Column(length: 20, options: ['default' => 'manager'])]
    private string $role = self::ROLE_MANAGER;

    #[ORM\ManyToOne]
    private ?User $invitedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $invitedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getBrand(): ?Brand { return $this->brand; }

    public function setBrand(?Brand $brand): static
    {
        $this->brand = $brand;
        return $this;
    }

    public function getRole(): string { return $this->role; }

    public function setRole(string $role): static
    {
        $this->role = $role;
        return $this;
    }

    public function isOwner(): bool { return $this->role === self::ROLE_OWNER; }

    public function getInvitedBy(): ?User { return $this->invitedBy; }

    public function setInvitedBy(?User $invitedBy): static
    {
        $this->invitedBy = $invitedBy;
        return $this;
    }

    public function getInvitedAt(): ?\DateTimeImmutable { return $this->invitedAt; }

    public function setInvitedAt(?\DateTimeImmutable $invitedAt): static
    {
        $this->invitedAt = $invitedAt;
        return $this;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }

    public function setAcceptedAt(?\DateTimeImmutable $acceptedAt): static
    {
        $this->acceptedAt = $acceptedAt;
        return $this;
    }

    public function isAccepted(): bool { return $this->acceptedAt !== null; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
