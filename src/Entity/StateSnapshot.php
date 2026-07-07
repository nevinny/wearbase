<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StateSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Снимок KPI-вектора проекта на момент tick'а советника (docs/advisor.md, шаг 1 цикла).
 * metrics — гибкий JSON (clicks/impressions/indexed/pipeline_stuck/drip_queue/leads/subscriptions
 * и что добавится позже, без миграции схемы). delta — пофилдовая разница к предыдущему снапшоту,
 * считается на этапе snapshot-команды (не здесь), может отсутствовать для самого первого снимка.
 */
#[ORM\Entity(repositoryClass: StateSnapshotRepository::class)]
#[ORM\Table(name: 'state_snapshot')]
#[ORM\Index(columns: ['created_at'], name: 'idx_state_snapshot_created_at')]
class StateSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::JSON)]
    private array $metrics = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $delta = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $v): static { $this->createdAt = $v; return $this; }

    public function getMetrics(): array { return $this->metrics; }
    public function setMetrics(array $v): static { $this->metrics = $v; return $this; }

    public function getDelta(): ?array { return $this->delta; }
    public function setDelta(?array $v): static { $this->delta = $v; return $this; }
}
