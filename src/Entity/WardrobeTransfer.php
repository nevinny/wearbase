<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeTransferRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Журнал передач вещи между членами семьи («мала — передали младшему»).
 * Append-only: записи не редактируются и не удаляются (hard-данные истории,
 * soft-delete не применяется). item.id стабилен при передаче, меняется только
 * item.user + item.item_no (сквозная нумерация нового носителя).
 */
#[ORM\Entity(repositoryClass: WardrobeTransferRepository::class)]
#[ORM\Table(name: 'wardrobe_transfer')]
class WardrobeTransfer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?WardrobeItem $item = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $fromUser = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $toUser = null;

    // Кто выполнил передачу (родитель)
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $actor = null;

    #[ORM\Column]
    private \DateTimeImmutable $transferredAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    public function __construct()
    {
        $this->transferredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getItem(): ?WardrobeItem { return $this->item; }

    public function setItem(?WardrobeItem $item): static
    {
        $this->item = $item;
        return $this;
    }

    public function getFromUser(): ?User { return $this->fromUser; }

    public function setFromUser(?User $fromUser): static
    {
        $this->fromUser = $fromUser;
        return $this;
    }

    public function getToUser(): ?User { return $this->toUser; }

    public function setToUser(?User $toUser): static
    {
        $this->toUser = $toUser;
        return $this;
    }

    public function getActor(): ?User { return $this->actor; }

    public function setActor(?User $actor): static
    {
        $this->actor = $actor;
        return $this;
    }

    public function getTransferredAt(): \DateTimeImmutable { return $this->transferredAt; }

    public function getNote(): ?string { return $this->note; }

    public function setNote(?string $note): static
    {
        $this->note = $note;
        return $this;
    }
}
