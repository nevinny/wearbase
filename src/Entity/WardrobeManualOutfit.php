<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeManualOutfitRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WardrobeManualOutfitRepository::class)]
#[ORM\Table(name: 'wardrobe_manual_outfit')]
#[ORM\Index(name: 'idx_manual_outfit_owner_deleted', columns: ['wardrobe_owner_id', 'deleted_at'])]
class WardrobeManualOutfit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $wardrobeOwner = null;

    #[ORM\Column(length: 100)]
    private string $title = 'Новый образ';

    /** @var Collection<int, WardrobeItem> */
    #[ORM\ManyToMany(targetEntity: WardrobeItem::class)]
    #[ORM\JoinTable(name: 'wardrobe_manual_outfit_item')]
    private Collection $items;

    /** @var array<int, array{itemId:int,x:float,y:float,width:float,rotation:float,z:int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $layout = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(User $user): static { $this->createdBy = $user; return $this; }
    public function getWardrobeOwner(): ?User { return $this->wardrobeOwner; }
    public function setWardrobeOwner(User $user): static { $this->wardrobeOwner = $user; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    /** @return Collection<int, WardrobeItem> */
    public function getItems(): Collection { return $this->items; }
    public function addItem(WardrobeItem $item): static { if (!$this->items->contains($item)) { $this->items->add($item); } return $this; }
    public function clearItems(): void { $this->items->clear(); }
    public function getLayout(): array { return $this->layout; }
    public function setLayout(array $layout): static { $this->layout = $layout; $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function softDelete(): void { $this->deletedAt = new \DateTimeImmutable(); }
}
