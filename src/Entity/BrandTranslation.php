<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BrandTranslationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Перевод бренда на конкретный язык.
 *
 * Оригинальный контент хранится в Brand (язык по умолчанию — ru).
 * Переводы хранятся здесь — по одной записи на язык.
 *
 * Уникальный ключ: (brand_id, locale) — один перевод на язык.
 */
#[ORM\Entity(repositoryClass: BrandTranslationRepository::class)]
#[ORM\Table(name: 'brand_translation')]
#[ORM\UniqueConstraint(name: 'uq_brand_translation', columns: ['brand_id', 'locale'])]
#[ORM\HasLifecycleCallbacks]
class BrandTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Brand::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Brand $brand = null;

    /** ISO 639-1: en, zh, ar, tr … */
    #[ORM\Column(length: 5)]
    #[Assert\NotBlank]
    private string $locale;

    /** Название бренда на этом языке */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    /** Краткое описание (для карточек) */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $anons = null;

    /** Полное описание */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** SEO: title */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitle = null;

    /** SEO: description */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $metaDescription = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s [%s]', $this->brand?->getTitle() ?? '?', $this->locale);
    }

    // ── Getters / Setters ─────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getBrand(): ?Brand { return $this->brand; }
    public function setBrand(?Brand $b): static { $this->brand = $b; return $this; }

    public function getLocale(): string { return $this->locale; }
    public function setLocale(string $l): static { $this->locale = $l; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $t): static { $this->title = $t; return $this; }

    public function getAnons(): ?string { return $this->anons; }
    public function setAnons(?string $a): static { $this->anons = $a; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }

    public function getMetaTitle(): ?string { return $this->metaTitle; }
    public function setMetaTitle(?string $t): static { $this->metaTitle = $t; return $this; }

    public function getMetaDescription(): ?string { return $this->metaDescription; }
    public function setMetaDescription(?string $d): static { $this->metaDescription = $d; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
