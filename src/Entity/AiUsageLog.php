<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AiUsageLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only журнал стоимости AI-запросов (токены + $) — фундамент под будущую
 * перепродажу AI-кредитов пользователям. Сама тарификация здесь НЕ строится,
 * только точный учёт факта расхода. Системный лог: НЕ soft-delete.
 * user null = системный/пайплайн-вызов (не привязан к пользователю фронта).
 */
#[ORM\Entity(repositoryClass: AiUsageLogRepository::class)]
#[ORM\Table(name: 'ai_usage_log')]
#[ORM\Index(columns: ['user_id', 'created_at'], name: 'idx_ai_usage_log_user_created')]
#[ORM\Index(columns: ['feature', 'created_at'], name: 'idx_ai_usage_log_feature_created')]
class AiUsageLog
{
    public const FEATURE_WARDROBE_PHOTO = 'wardrobe_photo';
    public const FEATURE_WARDROBE_URL   = 'wardrobe_url';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 40)]
    private ?string $feature = null;

    #[ORM\Column(length: 100)]
    private ?string $model = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $promptTokens = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $completionTokens = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 8, nullable: true)]
    private ?string $costUsd = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $v): static { $this->user = $v; return $this; }

    public function getFeature(): ?string { return $this->feature; }
    public function setFeature(string $v): static { $this->feature = $v; return $this; }

    public function getModel(): ?string { return $this->model; }
    public function setModel(string $v): static { $this->model = $v; return $this; }

    public function getPromptTokens(): int { return $this->promptTokens; }
    public function setPromptTokens(int $v): static { $this->promptTokens = $v; return $this; }

    public function getCompletionTokens(): int { return $this->completionTokens; }
    public function setCompletionTokens(int $v): static { $this->completionTokens = $v; return $this; }

    public function getCostUsd(): ?string { return $this->costUsd; }
    public function setCostUsd(?float $v): static { $this->costUsd = $v === null ? null : (string) $v; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $v): static { $this->createdAt = $v; return $this; }
}
