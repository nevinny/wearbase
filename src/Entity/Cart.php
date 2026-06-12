<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CartRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CartRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Cart
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // null = гостевая корзина
    #[ORM\OneToOne(targetEntity: User::class)]
    private ?User $user = null;

    // Для гостевых корзин — session ID
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $sessionId = null;

    /**
     * @var Collection<int, CartItem>
     */
    #[ORM\OneToMany(targetEntity: CartItem::class, mappedBy: 'cart', cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getSessionId(): ?string { return $this->sessionId; }

    public function setSessionId(?string $sessionId): static
    {
        $this->sessionId = $sessionId;
        return $this;
    }

    public function getItems(): Collection { return $this->items; }

    public function addItem(CartItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setCart($this);
        }
        return $this;
    }

    public function removeItem(CartItem $item): static
    {
        $this->items->removeElement($item);
        return $this;
    }

    public function findItemByVariant(ProductVariant $variant): ?CartItem
    {
        foreach ($this->items as $item) {
            if ($item->getVariant() === $variant) {
                return $item;
            }
        }
        return null;
    }

    public function getTotal(): float
    {
        return array_sum(array_map(
            fn(CartItem $item) => $item->getSubtotal(),
            $this->items->toArray()
        ));
    }

    public function getItemsCount(): int
    {
        return array_sum(array_map(
            fn(CartItem $item) => $item->getQty(),
            $this->items->toArray()
        ));
    }

    public function isEmpty(): bool { return $this->items->isEmpty(); }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /**
     * Группировка корзины по брендам (для разбивки на заказы при оформлении)
     * @return array<int, array{brand: Brand, items: CartItem[]}>
     */
    public function groupByBrand(): array
    {
        $groups = [];
        foreach ($this->items as $item) {
            $brand = $item->getVariant()->getProduct()->getBrand();
            $brandId = $brand->getId();
            if (!isset($groups[$brandId])) {
                $groups[$brandId] = ['brand' => $brand, 'items' => []];
            }
            $groups[$brandId]['items'][] = $item;
        }
        return array_values($groups);
    }
}
