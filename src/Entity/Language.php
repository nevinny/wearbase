<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LanguageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Язык интерфейса / локализации.
 *
 * Хранит список поддерживаемых языков платформы.
 * Код соответствует ISO 639-1 (2 символа, нижний регистр): ru, en, zh, ar …
 */
#[ORM\Entity(repositoryClass: LanguageRepository::class)]
#[ORM\Table(name: 'language')]
#[ORM\UniqueConstraint(name: 'uq_language_code', columns: ['code'])]
class Language
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ISO 639-1: ru, en, zh, ar, de, fr, … */
    #[ORM\Column(length: 5)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z]{2}(-[A-Z]{2})?$/', message: 'Используйте ISO 639-1 код языка (например: ru, en, zh)')]
    private string $code;

    /** Название на языке оригинала: Русский, English, 中文 */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private string $nativeName;

    /** Название на русском для интерфейса WEARBASE */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private string $nameRu;

    /** Направление текста: ltr (левое-правое) или rtl (правое-левое, напр. арабский) */
    #[ORM\Column(length: 3, options: ['default' => 'ltr'])]
    private string $textDirection = 'ltr';

    /** Доступен для выбора пользователями на фронте */
    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    /** Язык включён по умолчанию при создании нового контента */
    #[ORM\Column(options: ['default' => false])]
    private bool $isDefault = false;

    /** Порядок сортировки в списках */
    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->nativeName, $this->code);
    }

    // ── Getters / Setters ─────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): static { $this->code = $code; return $this; }

    public function getNativeName(): string { return $this->nativeName; }
    public function setNativeName(string $n): static { $this->nativeName = $n; return $this; }

    public function getNameRu(): string { return $this->nameRu; }
    public function setNameRu(string $n): static { $this->nameRu = $n; return $this; }

    public function getTextDirection(): string { return $this->textDirection; }
    public function setTextDirection(string $d): static { $this->textDirection = $d; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function isDefault(): bool { return $this->isDefault; }
    public function setIsDefault(bool $v): static { $this->isDefault = $v; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): static { $this->sortOrder = $v; return $this; }
}
