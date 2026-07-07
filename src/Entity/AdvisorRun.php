<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdvisorRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Аудит каждого tick'а советника (docs/advisor.md §Аудит + шаг 2): входы, дайджест, решения.
 * mode различает cron-тик (scheduled), реакцию на событие (event) и ручной запуск (ondemand).
 */
#[ORM\Entity(repositoryClass: AdvisorRunRepository::class)]
#[ORM\Table(name: 'advisor_run')]
#[ORM\Index(columns: ['ran_at'], name: 'idx_advisor_run_ran_at')]
class AdvisorRun
{
    public const MODE_SCHEDULED = 'scheduled';
    public const MODE_EVENT     = 'event';
    public const MODE_ONDEMAND  = 'ondemand';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $ranAt;

    #[ORM\Column(length: 16)]
    private ?string $mode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $inputsSummary = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $digestText = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $decisions = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->ranAt = new \DateTime();
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getRanAt(): \DateTimeInterface { return $this->ranAt; }
    public function setRanAt(\DateTimeInterface $v): static { $this->ranAt = $v; return $this; }

    public function getMode(): ?string { return $this->mode; }
    public function setMode(string $v): static { $this->mode = $v; return $this; }

    public function getInputsSummary(): ?string { return $this->inputsSummary; }
    public function setInputsSummary(?string $v): static { $this->inputsSummary = $v; return $this; }

    public function getDigestText(): ?string { return $this->digestText; }
    public function setDigestText(?string $v): static { $this->digestText = $v; return $this; }

    public function getDecisions(): ?array { return $this->decisions; }
    public function setDecisions(?array $v): static { $this->decisions = $v; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $v): static { $this->createdAt = $v; return $this; }
}
