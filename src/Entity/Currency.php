<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CurrencyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Валюта.
 *
 * Код соответствует ISO 4217 (3 символа, верхний регистр): RUB, USD, EUR, CNY, AED …
 *
 * Базовая валюта платформы — RUB (is_base = true).
 * Все цены хранятся в базовой валюте; конвертация делается на лету через ExchangeRate.
 */
#[ORM\Entity(repositoryClass: CurrencyRepository::class)]
#[ORM\Table(name: 'currency')]
#[ORM\UniqueConstraint(name: 'uq_currency_code', columns: ['code'])]
class Currency
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ISO 4217: RUB, USD, EUR, CNY, AED, TRY, KZT … */
    #[ORM\Column(length: 3)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[A-Z]{3}$/', message: 'Используйте ISO 4217 код (например: RUB, USD, EUR)')]
    private string $code;

    /** Символ: ₽, $, €, ¥, ₸ */
    #[ORM\Column(length: 10)]
    #[Assert\NotBlank]
    private string $symbol;

    /** Позиция символа: prefix ($99) или suffix (99₽) */
    #[ORM\Column(length: 6, options: ['default' => 'suffix'])]
    private string $symbolPosition = 'suffix';

    /** Название на русском: Российский рубль, Доллар США */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private string $nameRu;

    /** Название на английском: Russian Ruble, US Dollar */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private string $nameEn;

    /** Количество знаков после запятой: 2 для большинства, 0 для JPY, 3 для KWD */
    #[ORM\Column(options: ['default' => 2])]
    private int $decimalPlaces = 2;

    /** Разделитель дробной части: . или , */
    #[ORM\Column(length: 1, options: ['default' => '.'])]
    private string $decimalSeparator = '.';

    /** Разделитель групп разрядов: пробел, запятая или точка */
    #[ORM\Column(length: 1, options: ['default' => ' '])]
    private string $thousandsSeparator = ' ';

    /** Базовая валюта платформы (только одна может быть true) */
    #[ORM\Column(options: ['default' => false])]
    private bool $isBase = false;

    /** Активна для использования (видна пользователям) */
    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    /** Курсы обмена от этой валюты */
    #[ORM\OneToMany(targetEntity: ExchangeRate::class, mappedBy: 'baseCurrency', cascade: ['remove'])]
    private Collection $exchangeRates;

    public function __construct()
    {
        $this->exchangeRates = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->code, $this->symbol);
    }

    /**
     * Форматирует сумму в данной валюте.
     * Пример: 1500.00 → "1 500 ₽" (RUB) или "$1,500.00" (USD)
     */
    public function format(float $amount): string
    {
        $formatted = number_format(
            $amount,
            $this->decimalPlaces,
            $this->decimalSeparator,
            $this->thousandsSeparator
        );

        return $this->symbolPosition === 'prefix'
            ? $this->symbol . $formatted
            : $formatted . ' ' . $this->symbol;
    }

    // ── Getters / Setters ─────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): static { $this->code = $code; return $this; }

    public function getSymbol(): string { return $this->symbol; }
    public function setSymbol(string $s): static { $this->symbol = $s; return $this; }

    public function getSymbolPosition(): string { return $this->symbolPosition; }
    public function setSymbolPosition(string $p): static { $this->symbolPosition = $p; return $this; }

    public function getNameRu(): string { return $this->nameRu; }
    public function setNameRu(string $n): static { $this->nameRu = $n; return $this; }

    public function getNameEn(): string { return $this->nameEn; }
    public function setNameEn(string $n): static { $this->nameEn = $n; return $this; }

    public function getDecimalPlaces(): int { return $this->decimalPlaces; }
    public function setDecimalPlaces(int $n): static { $this->decimalPlaces = $n; return $this; }

    public function getDecimalSeparator(): string { return $this->decimalSeparator; }
    public function setDecimalSeparator(string $s): static { $this->decimalSeparator = $s; return $this; }

    public function getThousandsSeparator(): string { return $this->thousandsSeparator; }
    public function setThousandsSeparator(string $s): static { $this->thousandsSeparator = $s; return $this; }

    public function isBase(): bool { return $this->isBase; }
    public function setIsBase(bool $v): static { $this->isBase = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    /** @return Collection<int, ExchangeRate> */
    public function getExchangeRates(): Collection { return $this->exchangeRates; }
}
