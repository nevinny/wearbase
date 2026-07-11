<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FamilyInviteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Приглашение в семью для пользователей со своей почтой (взрослые / подросшие дети).
 * Родитель создаёт инвайт с ролью → ссылка /family/invite/{token}.
 * Одноразовый: accepted_at != null → «уже использовано».
 */
#[ORM\Entity(repositoryClass: FamilyInviteRepository::class)]
#[ORM\Table(name: 'family_invite')]
class FamilyInvite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Family $family = null;

    // 'parent' | 'child' (User::FAMILY_ROLE_*)
    #[ORM\Column(length: 10)]
    private ?string $role = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $token = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $acceptedBy = null;

    public function __construct()
    {
        $this->token = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getFamily(): ?Family { return $this->family; }

    public function setFamily(?Family $family): static
    {
        $this->family = $family;
        return $this;
    }

    public function getRole(): ?string { return $this->role; }

    public function setRole(string $role): static
    {
        $this->role = $role;
        return $this;
    }

    public function getToken(): ?string { return $this->token; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }

    public function setAcceptedAt(?\DateTimeImmutable $acceptedAt): static
    {
        $this->acceptedAt = $acceptedAt;
        return $this;
    }

    public function isAccepted(): bool { return $this->acceptedAt !== null; }

    public function getAcceptedBy(): ?User { return $this->acceptedBy; }

    public function setAcceptedBy(?User $acceptedBy): static
    {
        $this->acceptedBy = $acceptedBy;
        return $this;
    }
}
