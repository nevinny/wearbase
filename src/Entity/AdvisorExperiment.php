<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdvisorExperimentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Идея → ветка/sha → деплой → baseline → окно замера → вердикт (docs/advisor.md, шаг 5-8
 * цикла). Форма скопирована по смыслу с BrandContentRevision (baseline/окно/вердикт),
 * но на уровне проекта, а не одного бренда.
 */
#[ORM\Entity(repositoryClass: AdvisorExperimentRepository::class)]
#[ORM\Table(name: 'advisor_experiment')]
class AdvisorExperiment
{
    public const VERDICT_VALIDATED   = 'validated';
    public const VERDICT_REVERTED    = 'reverted';
    public const VERDICT_INCONCLUSIVE = 'inconclusive';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AdvisorIdea::class)]
    #[ORM\JoinColumn(name: 'idea_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AdvisorIdea $idea = null;

    #[ORM\Column(length: 255)]
    private ?string $branch = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $commitSha = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $deployedAt = null;

    #[ORM\ManyToOne(targetEntity: StateSnapshot::class)]
    #[ORM\JoinColumn(name: 'baseline_snapshot_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?StateSnapshot $baselineSnapshot = null;

    #[ORM\Column]
    private int $measureWindowDays = 7;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $verdict = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $learning = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getIdea(): ?AdvisorIdea { return $this->idea; }
    public function setIdea(AdvisorIdea $v): static { $this->idea = $v; return $this; }

    public function getBranch(): ?string { return $this->branch; }
    public function setBranch(string $v): static { $this->branch = $v; return $this; }

    public function getCommitSha(): ?string { return $this->commitSha; }
    public function setCommitSha(?string $v): static { $this->commitSha = $v; return $this; }

    public function getDeployedAt(): ?\DateTimeInterface { return $this->deployedAt; }
    public function setDeployedAt(?\DateTimeInterface $v): static { $this->deployedAt = $v; return $this; }

    public function getBaselineSnapshot(): ?StateSnapshot { return $this->baselineSnapshot; }
    public function setBaselineSnapshot(?StateSnapshot $v): static { $this->baselineSnapshot = $v; return $this; }

    public function getMeasureWindowDays(): int { return $this->measureWindowDays; }
    public function setMeasureWindowDays(int $v): static { $this->measureWindowDays = $v; return $this; }

    public function getVerdict(): ?string { return $this->verdict; }
    public function setVerdict(?string $v): static { $this->verdict = $v; return $this; }

    public function getLearning(): ?string { return $this->learning; }
    public function setLearning(?string $v): static { $this->learning = $v; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $v): static { $this->createdAt = $v; return $this; }
}
