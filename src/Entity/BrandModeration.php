<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BrandModerationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Премодерация самостоятельно зарегистрированного (или заявленного) бренда.
 * Одна строка на бренд (unique brand_id). Заполняется в два прохода:
 *  1. `queued`  — создаётся при регистрации/заявке (RegisterController, этап 2 — BrandClaim).
 *  2. `reviewed` — агент-конвейер (app:brand:moderate-tick, Mac) прогоняет ApplicationMatcher
 *     и пишет вердикт через POST /api/v1/moderation/verdict.
 * Финал — решение администратора по TG-кнопке (BrandModerationController):
 *  approved | changes_requested | rejected.
 */
#[ORM\Entity(repositoryClass: BrandModerationRepository::class)]
class BrandModeration
{
    public const SOURCE_SELF_REGISTER = 'self_register';
    public const SOURCE_CLAIM         = 'claim';
    public const SOURCE_MANUAL        = 'manual';

    public const STATUS_QUEUED            = 'queued';
    public const STATUS_REVIEWED          = 'reviewed';
    public const STATUS_APPROVED          = 'approved';
    public const STATUS_CHANGES_REQUESTED = 'changes_requested';
    public const STATUS_REJECTED          = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?Brand $brand = null;

    #[ORM\Column(length: 20)]
    private string $source = self::SOURCE_SELF_REGISTER;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_QUEUED])]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $verdict = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $identityMatch = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $controlProof = null;

    /** @var array<int,array{url:string,score:float,matched:array<string,bool>}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $evidence = null;

    /** @var array<int,string>|null коды красных флагов (+заметка) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $redFlags = null;

    /** @var array<int,string>|null коды: logo,price,inn,founding_year,production_place,description,links */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $missing = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $analyzeAttempts = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeInterface $analyzedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeInterface $decidedAt = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $decidedVia = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNote = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getBrand(): ?Brand { return $this->brand; }
    public function setBrand(?Brand $brand): static { $this->brand = $brand; return $this; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $source): static { $this->source = $source; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getVerdict(): ?string { return $this->verdict; }
    public function setVerdict(?string $verdict): static { $this->verdict = $verdict; return $this; }

    public function getIdentityMatch(): ?string { return $this->identityMatch; }
    public function setIdentityMatch(?string $v): static { $this->identityMatch = $v; return $this; }

    public function getControlProof(): ?string { return $this->controlProof; }
    public function setControlProof(?string $v): static { $this->controlProof = $v; return $this; }

    public function getEvidence(): ?array { return $this->evidence; }
    public function setEvidence(?array $evidence): static { $this->evidence = $evidence; return $this; }

    public function getRedFlags(): ?array { return $this->redFlags; }
    public function setRedFlags(?array $redFlags): static { $this->redFlags = $redFlags; return $this; }

    public function getMissing(): ?array { return $this->missing; }
    public function setMissing(?array $missing): static { $this->missing = $missing; return $this; }

    public function getSummary(): ?string { return $this->summary; }
    public function setSummary(?string $summary): static { $this->summary = $summary; return $this; }

    public function getAnalyzeAttempts(): int { return $this->analyzeAttempts; }
    public function setAnalyzeAttempts(int $n): static { $this->analyzeAttempts = $n; return $this; }
    public function incrementAnalyzeAttempts(): static { $this->analyzeAttempts++; return $this; }

    public function getAnalyzedAt(): ?\DateTimeInterface { return $this->analyzedAt; }
    public function setAnalyzedAt(?\DateTimeInterface $at): static { $this->analyzedAt = $at; return $this; }

    public function getDecidedAt(): ?\DateTimeInterface { return $this->decidedAt; }
    public function setDecidedAt(?\DateTimeInterface $at): static { $this->decidedAt = $at; return $this; }

    public function getDecidedVia(): ?string { return $this->decidedVia; }
    public function setDecidedVia(?string $via): static { $this->decidedVia = $via; return $this; }

    public function getAdminNote(): ?string { return $this->adminNote; }
    public function setAdminNote(?string $note): static { $this->adminNote = $note; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
