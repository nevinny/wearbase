<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductVariantRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductVariantRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ProductVariant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'variants')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $sku = null;

    // Размер (текстовое значение: XS, S, M, L, XL, XXL, 42, 44...)
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $size = null;

    // Цвет — текстовое название
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $color = null;

    // Цвет — HEX для отображения свотча
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $colorHex = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $price = null;

    // Зачёркнутая цена (старая цена / цена до скидки)
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $comparePrice = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $stockQty = 0;

    // Вес в граммах (для расчёта доставки)
    #[ORM\Column(nullable: true)]
    private ?int $weight = null;

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getProduct(): ?Product { return $this->product; }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;
        return $this;
    }

    public function getSku(): ?string { return $this->sku; }

    public function setSku(?string $sku): static
    {
        $this->sku = $sku;
        return $this;
    }

    public function getSize(): ?string { return $this->size; }

    public function setSize(?string $size): static
    {
        $this->size = $size;
        return $this;
    }

    public function getColor(): ?string { return $this->color; }

    public function setColor(?string $color): static
    {
        $this->color = $color;
        return $this;
    }

    public function getColorHex(): ?string { return $this->colorHex; }

    public function setColorHex(?string $colorHex): static
    {
        $this->colorHex = $colorHex;
        return $this;
    }

    public function getPrice(): ?string { return $this->price; }

    public function setPrice(string $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getPriceFloat(): float { return (float) $this->price; }

    public function getComparePrice(): ?string { return $this->comparePrice; }

    public function setComparePrice(?string $comparePrice): static
    {
        $this->comparePrice = $comparePrice;
        return $this;
    }

    public function hasDiscount(): bool
    {
        return $this->comparePrice !== null && (float) $this->comparePrice > (float) $this->price;
    }

    public function getDiscountPercent(): int
    {
        if (!$this->hasDiscount()) {
            return 0;
        }
        return (int) round((1 - (float) $this->price / (float) $this->comparePrice) * 100);
    }

    public function getStockQty(): int { return $this->stockQty; }

    public function setStockQty(int $stockQty): static
    {
        $this->stockQty = $stockQty;
        return $this;
    }

    public function isInStock(): bool { return $this->stockQty > 0; }

    public function getWeight(): ?int { return $this->weight; }

    public function setWeight(?int $weight): static
    {
        $this->weight = $weight;
        return $this;
    }

    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getTitle(): string
    {
        $parts = array_filter([$this->size, $this->color]);
        return implode(' / ', $parts) ?: 'Стандарт';
    }
}
