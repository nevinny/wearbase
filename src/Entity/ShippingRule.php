<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ShippingRuleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Правило доставки для страны.
 *
 * Одна страна может иметь несколько правил (разные перевозчики).
 * Цена хранится в RUB — конвертируется на лету при отображении.
 *
 * Carriers: courier, cdek, boxberry, pochta, dhl, fedex, pickup
 */
#[ORM\Entity(repositoryClass: ShippingRuleRepository::class)]
#[ORM\Table(name: 'shipping_rule')]
#[ORM\Index(name: 'idx_shipping_country', columns: ['country_id'])]
class ShippingRule
{
    public const CARRIER_COURIER  = 'courier';
    public const CARRIER_CDEK     = 'cdek';
    public const CARRIER_BOXBERRY = 'boxberry';
    public const CARRIER_POCHTA   = 'pochta';
    public const CARRIER_DHL      = 'dhl';
    public const CARRIER_FEDEX    = 'fedex';
    public const CARRIER_PICKUP   = 'pickup';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Country $country = null;

    /** Код перевозчика: courier, cdek, dhl … */
    #[ORM\Column(length: 30)]
    #[Assert\NotBlank]
    private string $carrier = self::CARRIER_CDEK;

    /** Название перевозчика для отображения */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private string $name;

    /** Цена доставки в рублях */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private string $priceRub = '0.00';

    /** Минимальный срок доставки (дней) */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $daysMin = 1;

    /** Максимальный срок доставки (дней) */
    #[ORM\Column]
    #[Assert\Positive]
    private int $daysMax = 7;

    /** Максимальный вес посылки (кг), null = без ограничений */
    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, nullable: true)]
    private ?string $maxWeightKg = null;

    /** Минимальная сумма заказа для бесплатной доставки (RUB), null = нет */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $freeFromRub = null;

    /** Трекинг-ссылка: %s заменяется на номер отслеживания */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $trackingUrl = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    public function __toString(): string
    {
        return sprintf('%s — %s', $this->country?->getNameRu() ?? '?', $this->name);
    }

    /** Срок доставки в виде строки: "1–3 дня" */
    public function getDeliveryLabel(): string
    {
        if ($this->daysMin === $this->daysMax) {
            return $this->daysMax . ' ' . $this->dayWord($this->daysMax);
        }
        return sprintf('%d–%d %s', $this->daysMin, $this->daysMax, $this->dayWord($this->daysMax));
    }

    private function dayWord(int $n): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) return 'дней';
        if ($n1 === 1) return 'день';
        if ($n1 >= 2 && $n1 <= 4) return 'дня';
        return 'дней';
    }

    // ── Getters / Setters ─────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getCountry(): ?Country { return $this->country; }
    public function setCountry(?Country $c): static { $this->country = $c; return $this; }

    public function getCarrier(): string { return $this->carrier; }
    public function setCarrier(string $c): static { $this->carrier = $c; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }

    public function getPriceRub(): string { return $this->priceRub; }
    public function setPriceRub(string $p): static { $this->priceRub = $p; return $this; }

    public function getDaysMin(): int { return $this->daysMin; }
    public function setDaysMin(int $d): static { $this->daysMin = $d; return $this; }

    public function getDaysMax(): int { return $this->daysMax; }
    public function setDaysMax(int $d): static { $this->daysMax = $d; return $this; }

    public function getMaxWeightKg(): ?string { return $this->maxWeightKg; }
    public function setMaxWeightKg(?string $w): static { $this->maxWeightKg = $w; return $this; }

    public function getFreeFromRub(): ?string { return $this->freeFromRub; }
    public function setFreeFromRub(?string $v): static { $this->freeFromRub = $v; return $this; }

    public function getTrackingUrl(): ?string { return $this->trackingUrl; }
    public function setTrackingUrl(?string $u): static { $this->trackingUrl = $u; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): static { $this->sortOrder = $v; return $this; }
}
