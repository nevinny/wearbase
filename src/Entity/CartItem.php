<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CartItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CartItemRepository::class)]
class CartItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Cart $cart = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?ProductVariant $variant = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $qty = 1;

    #[ORM\Column]
    private \DateTimeImmutable $addedAt;

    public function __construct()
    {
        $this->addedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCart(): ?Cart { return $this->cart; }

    public function setCart(?Cart $cart): static
    {
        $this->cart = $cart;
        return $this;
    }

    public function getVariant(): ?ProductVariant { return $this->variant; }

    public function setVariant(?ProductVariant $variant): static
    {
        $this->variant = $variant;
        return $this;
    }

    public function getQty(): int { return $this->qty; }

    public function setQty(int $qty): static
    {
        $this->qty = max(1, $qty);
        return $this;
    }

    public function getSubtotal(): float
    {
        return $this->variant ? $this->variant->getPriceFloat() * $this->qty : 0.0;
    }

    public function getAddedAt(): \DateTimeImmutable { return $this->addedAt; }
}
