<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrderItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderItemRepository::class)]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'orderItems')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Order $order = null;

    // nullable — на случай удалённого варианта товара
    #[ORM\ManyToOne]
    private ?ProductVariant $variant = null;

    // Снапшоты на момент заказа (неизменяемые)
    #[ORM\Column(length: 255)]
    private ?string $productTitle = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $variantTitle = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $price = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $qty = 1;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $total = null;

    public function getId(): ?int { return $this->id; }

    public function getOrder(): ?Order { return $this->order; }

    public function setOrder(?Order $order): static
    {
        $this->order = $order;
        return $this;
    }

    public function getVariant(): ?ProductVariant { return $this->variant; }

    public function setVariant(?ProductVariant $variant): static
    {
        $this->variant = $variant;
        return $this;
    }

    public function getProductTitle(): ?string { return $this->productTitle; }

    public function setProductTitle(string $productTitle): static
    {
        $this->productTitle = $productTitle;
        return $this;
    }

    public function getVariantTitle(): ?string { return $this->variantTitle; }

    public function setVariantTitle(?string $variantTitle): static
    {
        $this->variantTitle = $variantTitle;
        return $this;
    }

    public function getSku(): ?string { return $this->sku; }

    public function setSku(?string $sku): static
    {
        $this->sku = $sku;
        return $this;
    }

    public function getPrice(): ?string { return $this->price; }

    public function setPrice(string $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getQty(): int { return $this->qty; }

    public function setQty(int $qty): static
    {
        $this->qty = $qty;
        return $this;
    }

    public function getTotal(): ?string { return $this->total; }

    public function setTotal(string $total): static
    {
        $this->total = $total;
        return $this;
    }

    /**
     * Заполнение снапшота из варианта товара
     */
    public function fillFromVariant(ProductVariant $variant, int $qty): static
    {
        $this->variant = $variant;
        $this->qty = $qty;
        $this->price = $variant->getPrice();
        $this->productTitle = $variant->getProduct()?->getTitle() ?? '';
        $this->variantTitle = $variant->getTitle();
        $this->sku = $variant->getSku();
        $this->total = (string) round((float) $variant->getPrice() * $qty, 2);
        return $this;
    }
}
