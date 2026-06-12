<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CountryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Страна.
 *
 * Код соответствует ISO 3166-1 alpha-2 (2 символа, верхний регистр): RU, US, CN, AE …
 * Используется для геотаргетинга, адресов, налоговых правил и маршрутизации доставки.
 */
#[ORM\Entity(repositoryClass: CountryRepository::class)]
#[ORM\Table(name: 'country')]
#[ORM\UniqueConstraint(name: 'uq_country_code', columns: ['code'])]
class Country
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ISO 3166-1 alpha-2: RU, US, DE, CN, AE … */
    #[ORM\Column(length: 2)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[A-Z]{2}$/', message: 'Используйте ISO 3166-1 alpha-2 (например: RU, US)')]
    private string $code;

    /** ISO 3166-1 alpha-3: RUS, USA, DEU … (для совместимости с внешними системами) */
    #[ORM\Column(length: 3, nullable: true)]
    private ?string $code3 = null;

    /** Название на русском */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $nameRu;

    /** Название на английском */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $nameEn;

    /** Телефонный код: +7, +1, +49 … */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $phoneCode = null;

    /** Валюта страны по умолчанию */
    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Currency $defaultCurrency = null;

    /** Основной язык страны */
    #[ORM\ManyToOne(targetEntity: Language::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Language $defaultLanguage = null;

    /** Регион/континент для группировки: europe, asia, middle_east, americas, africa, oceania */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $region = null;

    /** Emoji-флаг: 🇷🇺, 🇺🇸 — генерируется автоматически из кода */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $flagEmoji = null;

    /** Страна включена (видна пользователям как доступный рынок) */
    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    /** Порядок сортировки */
    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    /** Города страны */
    #[ORM\OneToMany(targetEntity: City::class, mappedBy: 'country', cascade: ['remove'])]
    #[ORM\OrderBy(['nameRu' => 'ASC'])]
    private Collection $cities;

    public function __construct()
    {
        $this->cities = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('%s %s (%s)', $this->flagEmoji ?? '', $this->nameRu, $this->code);
    }

    /**
     * Генерирует emoji-флаг из ISO 3166-1 alpha-2 кода.
     * A→🇦 (regional indicator A = U+1F1E6), поэтому RU → 🇷🇺
     */
    public static function flagEmojiFromCode(string $code): string
    {
        $offset = 0x1F1E6 - ord('A');
        $chars  = mb_str_split(strtoupper($code));
        return implode('', array_map(
            static fn(string $c) => mb_chr(ord($c) + $offset),
            $chars
        ));
    }

    // ── Getters / Setters ─────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): static
    {
        $this->code = $code;
        if (!$this->flagEmoji) {
            $this->flagEmoji = self::flagEmojiFromCode($code);
        }
        return $this;
    }

    public function getCode3(): ?string { return $this->code3; }
    public function setCode3(?string $c): static { $this->code3 = $c; return $this; }

    public function getNameRu(): string { return $this->nameRu; }
    public function setNameRu(string $n): static { $this->nameRu = $n; return $this; }

    public function getNameEn(): string { return $this->nameEn; }
    public function setNameEn(string $n): static { $this->nameEn = $n; return $this; }

    public function getPhoneCode(): ?string { return $this->phoneCode; }
    public function setPhoneCode(?string $p): static { $this->phoneCode = $p; return $this; }

    public function getDefaultCurrency(): ?Currency { return $this->defaultCurrency; }
    public function setDefaultCurrency(?Currency $c): static { $this->defaultCurrency = $c; return $this; }

    public function getDefaultLanguage(): ?Language { return $this->defaultLanguage; }
    public function setDefaultLanguage(?Language $l): static { $this->defaultLanguage = $l; return $this; }

    public function getRegion(): ?string { return $this->region; }
    public function setRegion(?string $r): static { $this->region = $r; return $this; }

    public function getFlagEmoji(): ?string { return $this->flagEmoji; }
    public function setFlagEmoji(?string $e): static { $this->flagEmoji = $e; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): static { $this->sortOrder = $v; return $this; }

    /** @return Collection<int, City> */
    public function getCities(): Collection { return $this->cities; }
}
