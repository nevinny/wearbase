<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExchangeRateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Курс обмена валюты.
 *
 * Хранит курс: 1 единица baseCurrency = rate единиц targetCurrency.
 * Пример: baseCurrency=RUB, targetCurrency=USD, rate=0.011  →  1 ₽ = 0.011 $
 * Или наоборот: base=USD, target=RUB, rate=90.5  →  1 $ = 90.5 ₽
 *
 * Записи обновляются ежедневно консольной командой app:currency:update-rates.
 * Источники: ЦБ РФ (cbr.ru) или fixer.io / openexchangerates.org.
 *
 * Для пересчёта цены из базовой валюты платформы (RUB) в целевую:
 *   price_target = price_base * rate  (где base=RUB)
 */
#[ORM\Entity(repositoryClass: ExchangeRateRepository::class)]
#[ORM\Table(name: 'exchange_rate')]
#[ORM\UniqueConstraint(
    name: 'uq_exchange_rate_pair_date',
    columns: ['base_currency_id', 'target_currency_id', 'rate_date']
)]
#[ORM\Index(name: 'idx_exchange_rate_date', columns: ['rate_date'])]
#[ORM\HasLifecycleCallbacks]
class ExchangeRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Валюта-источник (обычно RUB — базовая валюта платформы) */
    #[ORM\ManyToOne(targetEntity: Currency::class, inversedBy: 'exchangeRates')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Currency $baseCurrency = null;

    /** Валюта-цель (USD, EUR, CNY …) */
    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Currency $targetCurrency = null;

    /**
     * Курс: 1 baseCurrency = rate targetCurrency.
     * Пример: base=RUB, target=USD, rate=0.011
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 8)]
    #[Assert\Positive]
    private string $rate;

    /** Дата котировки (без времени) */
    #[ORM\Column(type: 'date')]
    private \DateTimeInterface $rateDate;

    /** Источник курса: cbr, fixer, manual … */
    #[ORM\Column(length: 30, options: ['default' => 'manual'])]
    private string $source = 'manual';

    /** Время последнего обновления записи */
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->rateDate  = new \DateTimeImmutable('today');
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Конвертирует сумму из базовой валюты в целевую.
     */
    public function convert(float $amount): float
    {
        return $amount * (float) $this->rate;
    }

    public function __toString(): string
    {
        return sprintf(
            '1 %s = %s %s (%s)',
            $this->baseCurrency?->getCode() ?? '?',
            $this->rate,
            $this->targetCurrency?->getCode() ?? '?',
            $this->rateDate->format('d.m.Y')
        );
    }

    // ── Getters / Setters ─────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getBaseCurrency(): ?Currency { return $this->baseCurrency; }
    public function setBaseCurrency(?Currency $c): static { $this->baseCurrency = $c; return $this; }

    public function getTargetCurrency(): ?Currency { return $this->targetCurrency; }
    public function setTargetCurrency(?Currency $c): static { $this->targetCurrency = $c; return $this; }

    public function getRate(): string { return $this->rate; }
    public function setRate(string $rate): static { $this->rate = $rate; return $this; }

    public function getRateDate(): \DateTimeInterface { return $this->rateDate; }
    public function setRateDate(\DateTimeInterface $d): static { $this->rateDate = $d; return $this; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $s): static { $this->source = $s; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
