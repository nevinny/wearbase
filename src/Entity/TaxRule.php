<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaxRuleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Налоговое правило для страны/региона.
 *
 * Хранит ставку НДС и дополнительные пошлины для заказов
 * из конкретной страны. Все ставки — в процентах.
 *
 * Типы налогов:
 *   vat     — НДС (Value Added Tax, EU/UK)
 *   gst     — GST (Goods and Services Tax, AU/NZ/CA)
 *   sales   — Sales Tax (USA)
 *   customs — Таможенная пошлина (импорт)
 *   none    — Нет налогов (B2B, free zones и т.д.)
 */
#[ORM\Entity(repositoryClass: TaxRuleRepository::class)]
#[ORM\Table(name: 'tax_rule')]
#[ORM\Index(name: 'idx_tax_country', columns: ['country_id'])]
class TaxRule
{
    public const TYPE_VAT     = 'vat';
    public const TYPE_GST     = 'gst';
    public const TYPE_SALES   = 'sales';
    public const TYPE_CUSTOMS = 'customs';
    public const TYPE_NONE    = 'none';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Country $country = null;

    /** Название правила: "НДС Германия 19%", "GST Австралия 10%" */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $name = '';

    /** Тип налога */
    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::TYPE_VAT, self::TYPE_GST, self::TYPE_SALES, self::TYPE_CUSTOMS, self::TYPE_NONE])]
    private string $taxType = self::TYPE_VAT;

    /** Ставка налога, % (например 20.00 = 20%) */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(100)]
    private string $rate = '0.00';

    /** Ставка таможенной пошлины (%), 0 если не применима */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    #[Assert\PositiveOrZero]
    private string $customsRate = '0.00';

    /** Порог стоимости заказа (RUB), до которого пошлины не взимаются (null = всегда) */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $customsThresholdRub = null;

    /** Налог уже включён в цену (VAT-inclusive pricing) */
    #[ORM\Column(options: ['default' => false])]
    private bool $isInclusive = false;

    /** Применяется к физическим лицам */
    #[ORM\Column(options: ['default' => true])]
    private bool $appliesToB2c = true;

    /** Применяется к юридическим лицам */
    #[ORM\Column(options: ['default' => false])]
    private bool $appliesToB2b = false;

    /** Ссылка на официальный источник */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceUrl = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    public function __toString(): string
    {
        return sprintf('%s — %s', $this->country?->getNameRu() ?? '?', $this->name);
    }

    /**
     * Рассчитать сумму налога от суммы (без налога).
     */
    public function calculateTax(float $amount): float
    {
        if ($this->isInclusive) {
            // НДС включён: tax = amount * rate / (100 + rate)
            $rate = (float) $this->rate;
            return $amount * $rate / (100 + $rate);
        }
        return $amount * (float) $this->rate / 100;
    }

    /**
     * Сумма с налогом.
     */
    public function amountWithTax(float $amountExcl): float
    {
        if ($this->isInclusive) {
            return $amountExcl; // уже включено
        }
        return $amountExcl * (1 + (float) $this->rate / 100);
    }

    // ── Getters / Setters ─────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getCountry(): ?Country { return $this->country; }
    public function setCountry(?Country $c): static { $this->country = $c; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }

    public function getTaxType(): string { return $this->taxType; }
    public function setTaxType(string $t): static { $this->taxType = $t; return $this; }

    public function getRate(): string { return $this->rate; }
    public function setRate(string $r): static { $this->rate = $r; return $this; }

    public function getCustomsRate(): string { return $this->customsRate; }
    public function setCustomsRate(string $r): static { $this->customsRate = $r; return $this; }

    public function getCustomsThresholdRub(): ?string { return $this->customsThresholdRub; }
    public function setCustomsThresholdRub(?string $v): static { $this->customsThresholdRub = $v; return $this; }

    public function isInclusive(): bool { return $this->isInclusive; }
    public function setIsInclusive(bool $v): static { $this->isInclusive = $v; return $this; }

    public function isAppliesToB2c(): bool { return $this->appliesToB2c; }
    public function setAppliesToB2c(bool $v): static { $this->appliesToB2c = $v; return $this; }

    public function isAppliesToB2b(): bool { return $this->appliesToB2b; }
    public function setAppliesToB2b(bool $v): static { $this->appliesToB2b = $v; return $this; }

    public function getSourceUrl(): ?string { return $this->sourceUrl; }
    public function setSourceUrl(?string $u): static { $this->sourceUrl = $u; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): static { $this->sortOrder = $v; return $this; }
}
