<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BrandContentRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Версия контента бренда (append-only) + запись closed-loop эксперимента.
 *
 * Тройка description/meta_title/meta_description снимается при каждой смене контента;
 * активная ревизия зеркалит живые brand.* (сайт читает по-прежнему из brand). Промоутится
 * только версия, прошедшая quality-gate. Поля gsc_* и verdict ведут эксперимент:
 * baseline на старте → окно measure_after → win/loss/neutral/not_indexed → keep/откат/реген.
 */
#[ORM\Entity(repositoryClass: BrandContentRevisionRepository::class)]
#[ORM\Table(name: 'brand_content_revision')]
class BrandContentRevision
{
    public const SOURCE_LEGACY   = 'legacy';
    public const SOURCE_RAG      = 'rag';
    public const SOURCE_MANUAL   = 'manual';
    public const SOURCE_IMPORT   = 'import';
    public const SOURCE_ROLLBACK = 'rollback';

    public const VERDICT_PENDING     = 'pending';
    public const VERDICT_WIN         = 'win';
    public const VERDICT_LOSS        = 'loss';
    public const VERDICT_NEUTRAL     = 'neutral';
    public const VERDICT_NOT_INDEXED = 'not_indexed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Brand::class)]
    #[ORM\JoinColumn(name: 'brand_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Brand $brand = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitle = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $metaDescription = null;

    #[ORM\Column(length: 20)]
    private string $source = self::SOURCE_RAG;

    #[ORM\Column]
    private bool $grounded = false;

    #[ORM\Column(nullable: true)]
    private ?float $retrievalScore = null;

    #[ORM\Column]
    private bool $isActive = false;

    #[ORM\Column]
    private int $attempt = 1;

    /** Сколько ОКОН подряд показали loss (антифлаппинг: реген только после подтверждения, ≥2). */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $lossStreak = 0;

    #[ORM\Column(nullable: true)]
    private ?int $prevRevisionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $measureAfter = null;

    #[ORM\Column(length: 16)]
    private string $verdict = self::VERDICT_PENDING;

    #[ORM\Column(nullable: true)]
    private ?int $gscImprBefore = null;

    #[ORM\Column(nullable: true)]
    private ?int $gscClicksBefore = null;

    #[ORM\Column(nullable: true)]
    private ?bool $gscIndexedBefore = null;

    #[ORM\Column(nullable: true)]
    private ?int $gscImprAfter = null;

    #[ORM\Column(nullable: true)]
    private ?int $gscClicksAfter = null;

    #[ORM\Column(nullable: true)]
    private ?bool $gscIndexedAfter = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getBrand(): ?Brand { return $this->brand; }
    public function setBrand(?Brand $brand): static { $this->brand = $brand; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): static { $this->description = $v; return $this; }

    public function getMetaTitle(): ?string { return $this->metaTitle; }
    public function setMetaTitle(?string $v): static { $this->metaTitle = $v; return $this; }

    public function getMetaDescription(): ?string { return $this->metaDescription; }
    public function setMetaDescription(?string $v): static { $this->metaDescription = $v; return $this; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $v): static { $this->source = $v; return $this; }

    public function isGrounded(): bool { return $this->grounded; }
    public function setGrounded(bool $v): static { $this->grounded = $v; return $this; }

    public function getRetrievalScore(): ?float { return $this->retrievalScore; }
    public function setRetrievalScore(?float $v): static { $this->retrievalScore = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getAttempt(): int { return $this->attempt; }
    public function setAttempt(int $v): static { $this->attempt = $v; return $this; }

    public function getLossStreak(): int { return $this->lossStreak; }
    public function setLossStreak(int $v): static { $this->lossStreak = $v; return $this; }

    public function getPrevRevisionId(): ?int { return $this->prevRevisionId; }
    public function setPrevRevisionId(?int $v): static { $this->prevRevisionId = $v; return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $v): static { $this->note = $v; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $v): static { $this->createdAt = $v; return $this; }

    public function getMeasureAfter(): ?\DateTimeInterface { return $this->measureAfter; }
    public function setMeasureAfter(?\DateTimeInterface $v): static { $this->measureAfter = $v; return $this; }

    public function getVerdict(): string { return $this->verdict; }
    public function setVerdict(string $v): static { $this->verdict = $v; return $this; }

    public function getGscImprBefore(): ?int { return $this->gscImprBefore; }
    public function setGscImprBefore(?int $v): static { $this->gscImprBefore = $v; return $this; }

    public function getGscClicksBefore(): ?int { return $this->gscClicksBefore; }
    public function setGscClicksBefore(?int $v): static { $this->gscClicksBefore = $v; return $this; }

    public function getGscIndexedBefore(): ?bool { return $this->gscIndexedBefore; }
    public function setGscIndexedBefore(?bool $v): static { $this->gscIndexedBefore = $v; return $this; }

    public function getGscImprAfter(): ?int { return $this->gscImprAfter; }
    public function setGscImprAfter(?int $v): static { $this->gscImprAfter = $v; return $this; }

    public function getGscClicksAfter(): ?int { return $this->gscClicksAfter; }
    public function setGscClicksAfter(?int $v): static { $this->gscClicksAfter = $v; return $this; }

    public function getGscIndexedAfter(): ?bool { return $this->gscIndexedAfter; }
    public function setGscIndexedAfter(?bool $v): static { $this->gscIndexedAfter = $v; return $this; }
}
