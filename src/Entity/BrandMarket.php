<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BrandMarketRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Присутствие бренда на конкретном рынке (стране).
 *
 * Позволяет брендам указывать:
 *  - в каких странах они работают
 *  - базовую цену доставки для этой страны
 *  - наличие локального склада / партнёра
 *  - статус (активен / пауза / скоро)
 *
 * Уникальный ключ: (brand_id, country_id) — один маркет на страну.
 */
#[ORM\Entity(repositoryClass: BrandMarketRepository::class)]
#[ORM\Table(name: 'brand_market')]
#[ORM\UniqueConstraint(name: 'uq_brand_market', columns: ['brand_id', 'country_id'])]
#[ORM\Index(name: 'idx_brand_market_country', columns: ['country_id'])]
class BrandMarket
{
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_PAUSED  = 'paused';
    public const STATUS_COMING  = 'coming_soon';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Brand::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Brand $brand = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Country $country = null;

    /** Статус: active | paused | coming_soon */
    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ACTIVE;

    /** Есть ли локальный склад / фулфилмент-партнёр в этой стране */
    #[ORM\Column(options: ['default' => false])]
    private bool $hasLocalWarehouse = false;

    /** Стоимость доставки в рублях (null = используется глобальное правило ShippingRule) */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $customShippingRub = null;

    /** Минимальная сумма заказа (RUB) для бесплатной доставки */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $freeShippingFromRub = null;

    /** Примерный срок доставки (дней) */
    #[ORM\Column(nullable: true)]
    private ?int $estimatedDays = null;

    /** Дата начала работы в стране */
    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeImmutable $activeFrom = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    public function __toString(): string
    {
        return sprintf('%s → %s', $this->brand?->getTitle() ?? '?', $this->country?->getNameRu() ?? '?');
    }

    // ── Getters / Setters ─────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getBrand(): ?Brand { return $this->brand; }
    public function setBrand(?Brand $b): static { $this->brand = $b; return $this; }

    public function getCountry(): ?Country { return $this->country; }
    public function setCountry(?Country $c): static { $this->country = $c; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }

    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }

    public function hasLocalWarehouse(): bool { return $this->hasLocalWarehouse; }
    public function setHasLocalWarehouse(bool $v): static { $this->hasLocalWarehouse = $v; return $this; }

    public function getCustomShippingRub(): ?string { return $this->customShippingRub; }
    public function setCustomShippingRub(?string $v): static { $this->customShippingRub = $v; return $this; }

    public function getFreeShippingFromRub(): ?string { return $this->freeShippingFromRub; }
    public function setFreeShippingFromRub(?string $v): static { $this->freeShippingFromRub = $v; return $this; }

    public function getEstimatedDays(): ?int { return $this->estimatedDays; }
    public function setEstimatedDays(?int $d): static { $this->estimatedDays = $d; return $this; }

    public function getActiveFrom(): ?\DateTimeImmutable { return $this->activeFrom; }
    public function setActiveFrom(?\DateTimeImmutable $d): static { $this->activeFrom = $d; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): static { $this->sortOrder = $v; return $this; }
}
