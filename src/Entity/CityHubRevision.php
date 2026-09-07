<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CityHubRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Версия контента городского хаба (append-only) + журнал closed-loop эксперимента.
 *
 * Измеримое подмножество BrandContentRevision (см. её докблок) — тот же паттерн
 * baseline → окно замера → win/loss/neutral/not_indexed → keep/откат, но БЕЗ
 * grounded/retrieval_score/loss_streak: контент хаба не RAG-генерится из корпуса
 * бренда и антифлаппинг для него излишен (см. ClosedLoopJudge/EvaluateExperimentsCommand).
 * slug хранится строкой (не только через hub) — ревизия остаётся осмысленной,
 * даже если сам CityHub когда-нибудь удалят. verdict использует константы
 * BrandContentRevision::VERDICT_* — они не про бренд, а про исход эксперимента вообще.
 */
#[ORM\Entity(repositoryClass: CityHubRevisionRepository::class)]
#[ORM\Table(name: 'city_hub_revision')]
class CityHubRevision
{
    public const SOURCE_GENERATED = 'generated';
    public const SOURCE_MANUAL    = 'manual';
    public const SOURCE_ROLLBACK  = 'rollback';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CityHub::class)]
    #[ORM\JoinColumn(name: 'city_hub_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CityHub $hub = null;

    #[ORM\Column(length: 255)]
    private string $slug;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $h1 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitle = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $metaDescription = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $intro = null;

    /** @var array<int, array{question: string, answer: string}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $faq = null;

    #[ORM\Column(length: 20)]
    private string $source = self::SOURCE_GENERATED;

    /** Балл ArticleQaService (overall) на момент генерации — связка «вход → исход по GSC». */
    #[ORM\Column(nullable: true)]
    private ?float $qaOverall = null;

    #[ORM\Column]
    private bool $isActive = false;

    #[ORM\Column]
    private int $attempt = 1;

    #[ORM\Column(nullable: true)]
    private ?int $prevRevisionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $measureAfter = null;

    #[ORM\Column(length: 16)]
    private string $verdict = BrandContentRevision::VERDICT_PENDING;

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

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getHub(): ?CityHub { return $this->hub; }
    public function setHub(?CityHub $hub): static { $this->hub = $hub; return $this; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $v): static { $this->slug = $v; return $this; }

    public function getH1(): ?string { return $this->h1; }
    public function setH1(?string $v): static { $this->h1 = $v; return $this; }

    public function getMetaTitle(): ?string { return $this->metaTitle; }
    public function setMetaTitle(?string $v): static { $this->metaTitle = $v; return $this; }

    public function getMetaDescription(): ?string { return $this->metaDescription; }
    public function setMetaDescription(?string $v): static { $this->metaDescription = $v; return $this; }

    public function getIntro(): ?string { return $this->intro; }
    public function setIntro(?string $v): static { $this->intro = $v; return $this; }

    /** @return array<int, array{question: string, answer: string}>|null */
    public function getFaq(): ?array { return $this->faq; }
    /** @param array<int, array{question: string, answer: string}>|null $v */
    public function setFaq(?array $v): static { $this->faq = $v; return $this; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $v): static { $this->source = $v; return $this; }

    public function getQaOverall(): ?float { return $this->qaOverall; }
    public function setQaOverall(?float $v): static { $this->qaOverall = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getAttempt(): int { return $this->attempt; }
    public function setAttempt(int $v): static { $this->attempt = $v; return $this; }

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
}
