<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FamilyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Семья — группа пользователей (client) с общим доступом к гардеробам.
 * Создаётся лениво при добавлении первого ребёнка или первом инвайте;
 * создатель становится owner и получает family_role = 'parent'.
 */
#[ORM\Entity(repositoryClass: FamilyRepository::class)]
#[ORM\Table(name: 'family')]
class Family
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getOwner(): ?User { return $this->owner; }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
