<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WardrobeRepository::class)]
#[ORM\Table(name: 'wardrobe')]
#[ORM\Index(name: 'idx_wardrobe_owner_default', columns: ['owner_user_id', 'is_default', 'deleted_at'])]
#[ORM\HasLifecycleCallbacks]
class Wardrobe
{
    public const TYPE_PERSONAL = 'personal';
    public const STATUS_ACTIVE = 'active';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'wardrobes')]
    #[ORM\JoinColumn(name: 'owner_user_id', nullable: false)]
    private ?User $owner = null;

    #[ORM\Column(length: 120)]
    private string $name = 'Мой гардероб';

    #[ORM\Column(length: 20, options: ['default' => self::TYPE_PERSONAL])]
    private string $type = self::TYPE_PERSONAL;

    #[ORM\Column(options: ['default' => true])]
    private bool $isDefault = true;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    /**
     * @var Collection<int, WardrobeItem>
     */
    #[ORM\OneToMany(targetEntity: WardrobeItem::class, mappedBy: 'wardrobe')]
    private Collection $items;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getOwner(): ?User { return $this->owner; }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;
        return $this;
    }

    public function getName(): string { return $this->name; }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getType(): string { return $this->type; }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function isDefault(): bool { return $this->isDefault; }

    public function setDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;
        return $this;
    }

    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getItems(): Collection { return $this->items; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
