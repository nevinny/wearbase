<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductTranslationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Перевод товара на конкретный язык.
 *
 * Уникальный ключ: (product_id, locale).
 */
#[ORM\Entity(repositoryClass: ProductTranslationRepository::class)]
#[ORM\Table(name: 'product_translation')]
#[ORM\UniqueConstraint(name: 'uq_product_translation', columns: ['product_id', 'locale'])]
#[ORM\HasLifecycleCallbacks]
class ProductTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Product $product = null;

    #[ORM\Column(length: 5)]
    #[Assert\NotBlank]
    private string $locale;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $anons = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitle = null;

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

    // ── Getters / Setters ─────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getProduct(): ?Product { return $this->product; }
    public function setProduct(?Product $p): static { $this->product = $p; return $this; }

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
