<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdvisorIdeaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Бэклог идей советника (docs/advisor.md, шаг 2-4 цикла). Каждая идея несёт provenance
 * (rag_citations — id RAG-чанков) для anti-hallucination и ICE-компоненты (impact/confidence/ease,
 * 1-10) для приоритизации; ice_score хранится денормализованно (произведение), не считается на лету.
 * dedupe_hash — дедуп против ВСЕХ прошлых идей, включая отклонённые (докстрока §«Дедуп»).
 */
#[ORM\Entity(repositoryClass: AdvisorIdeaRepository::class)]
#[ORM\Table(name: 'advisor_idea')]
#[ORM\Index(columns: ['status'], name: 'idx_advisor_idea_status')]
#[ORM\Index(columns: ['dedupe_hash'], name: 'idx_advisor_idea_dedupe_hash')]
class AdvisorIdea
{
    public const STATUS_PROPOSED    = 'proposed';
    public const STATUS_APPROVED    = 'approved';
    public const STATUS_REJECTED    = 'rejected';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SHIPPED     = 'shipped';
    public const STATUS_MEASURING   = 'measuring';
    public const STATUS_VALIDATED   = 'validated';
    public const STATUS_REVERTED    = 'reverted';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $hypothesis = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceSignal = null;

    /** Id RAG-чанков-провенанс (anti-hallucination, docs/advisor.md §RAG). */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $ragCitations = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $impact = 1;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $confidence = 1;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $ease = 1;

    /** Денормализованное произведение impact*confidence*ease — не пересчитывать на лету. */
    #[ORM\Column]
    private int $iceScore = 1;

    #[ORM\Column(length: 32, options: ['default' => self::STATUS_PROPOSED])]
    private string $status = self::STATUS_PROPOSED;

    #[ORM\Column(length: 64)]
    private ?string $dedupeHash = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $embeddingRef = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rejectedReason = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(string $v): static { $this->title = $v; return $this; }

    public function getHypothesis(): ?string { return $this->hypothesis; }
    public function setHypothesis(string $v): static { $this->hypothesis = $v; return $this; }

    public function getSourceSignal(): ?string { return $this->sourceSignal; }
    public function setSourceSignal(?string $v): static { $this->sourceSignal = $v; return $this; }

    public function getRagCitations(): ?array { return $this->ragCitations; }
    public function setRagCitations(?array $v): static { $this->ragCitations = $v; return $this; }

    public function getImpact(): int { return $this->impact; }
    public function setImpact(int $v): static { $this->impact = $v; return $this; }

    public function getConfidence(): int { return $this->confidence; }
    public function setConfidence(int $v): static { $this->confidence = $v; return $this; }

    public function getEase(): int { return $this->ease; }
    public function setEase(int $v): static { $this->ease = $v; return $this; }

    public function getIceScore(): int { return $this->iceScore; }
    public function setIceScore(int $v): static { $this->iceScore = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }

    public function getDedupeHash(): ?string { return $this->dedupeHash; }
    public function setDedupeHash(string $v): static { $this->dedupeHash = $v; return $this; }

    public function getEmbeddingRef(): ?string { return $this->embeddingRef; }
    public function setEmbeddingRef(?string $v): static { $this->embeddingRef = $v; return $this; }

    public function getRejectedReason(): ?string { return $this->rejectedReason; }
    public function setRejectedReason(?string $v): static { $this->rejectedReason = $v; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $v): static { $this->createdAt = $v; return $this; }

    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeInterface $v): static { $this->updatedAt = $v; return $this; }
}
