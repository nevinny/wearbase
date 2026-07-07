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

    /** Стадии гитфлоу-исполнения (Фаза B воркер ведёт по этой статус-машине). */
    public const STAGE_PENDING           = 'pending';
    public const STAGE_BRANCH_CREATED    = 'branch_created';
    public const STAGE_IMPLEMENTING      = 'implementing';
    public const STAGE_IMPLEMENTED       = 'implemented';
    public const STAGE_TESTS_PASSED      = 'tests_passed';
    public const STAGE_TESTS_FAILED      = 'tests_failed';
    public const STAGE_RC_READY          = 'rc_ready';
    public const STAGE_AWAITING_APPROVAL = 'awaiting_approval';
    public const STAGE_APPROVED          = 'approved';
    public const STAGE_DEPLOYED          = 'deployed';
    public const STAGE_MEASURING         = 'measuring';
    public const STAGE_DONE              = 'done';

    /** Итог прогона тестов (гейт брокера). */
    public const TEST_PASSED = 'passed';
    public const TEST_FAILED = 'failed';

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

    /** Курсор статус-машины исполнения (см. STAGE_*). */
    #[ORM\Column(length: 32, options: ['default' => self::STAGE_PENDING])]
    private string $stage = self::STAGE_PENDING;

    /** Копия класса действия идеи (a|b|c) — маршрутизация воркера/брокера. */
    #[ORM\Column(length: 1, nullable: true)]
    private ?string $actionClass = null;

    /** Путь git-worktree, где воркер реализует спеку (изоляция от рабочего дерева). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $worktreePath = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $testStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $testReport = null;

    /** Результаты пре-деплой гейтов (php -l/lint/policy/migrations/smoke). */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $gateReport = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $prUrl = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $failureNote = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $approvedBy = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $approvedAt = null;

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

    public function getStage(): string { return $this->stage; }
    public function setStage(string $v): static { $this->stage = $v; return $this; }

    public function getActionClass(): ?string { return $this->actionClass; }
    public function setActionClass(?string $v): static { $this->actionClass = $v; return $this; }

    public function getWorktreePath(): ?string { return $this->worktreePath; }
    public function setWorktreePath(?string $v): static { $this->worktreePath = $v; return $this; }

    public function getTestStatus(): ?string { return $this->testStatus; }
    public function setTestStatus(?string $v): static { $this->testStatus = $v; return $this; }

    public function getTestReport(): ?string { return $this->testReport; }
    public function setTestReport(?string $v): static { $this->testReport = $v; return $this; }

    public function getGateReport(): ?array { return $this->gateReport; }
    public function setGateReport(?array $v): static { $this->gateReport = $v; return $this; }

    public function getPrUrl(): ?string { return $this->prUrl; }
    public function setPrUrl(?string $v): static { $this->prUrl = $v; return $this; }

    public function getAttempts(): int { return $this->attempts; }
    public function setAttempts(int $v): static { $this->attempts = $v; return $this; }

    public function getFailureNote(): ?string { return $this->failureNote; }
    public function setFailureNote(?string $v): static { $this->failureNote = $v; return $this; }

    public function getApprovedBy(): ?string { return $this->approvedBy; }
    public function setApprovedBy(?string $v): static { $this->approvedBy = $v; return $this; }

    public function getApprovedAt(): ?\DateTimeInterface { return $this->approvedAt; }
    public function setApprovedAt(?\DateTimeInterface $v): static { $this->approvedAt = $v; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $v): static { $this->createdAt = $v; return $this; }
}
