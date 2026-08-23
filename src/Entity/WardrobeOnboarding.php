<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeOnboardingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WardrobeOnboardingRepository::class)]
#[ORM\Table(name: 'wardrobe_onboarding')]
#[ORM\UniqueConstraint(name: 'uniq_wardrobe_onboarding_subject', columns: ['subject_id'])]
class WardrobeOnboarding
{
    public const STAGE_INTRO = 'intro';
    public const STAGE_CAPSULE = 'capsule';
    public const STAGE_OUTFIT = 'outfit';
    public const STAGE_COMPLETED = 'completed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $subject;

    #[ORM\Column(length: 16)]
    private string $stage = self::STAGE_INTRO;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $activeBatchId = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $skippedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $subject)
    {
        $this->subject = $subject;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSubject(): User { return $this->subject; }
    public function getStage(): string { return $this->stage; }
    public function getActiveBatchId(): ?string { return $this->activeBatchId; }
    public function getSkippedAt(): ?\DateTimeImmutable { return $this->skippedAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function startCapsule(string $batchId): void
    {
        if ($this->isCompleted()) {
            throw new \DomainException('Онбординг уже завершён');
        }
        if (strlen($batchId) > 36 || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $batchId) !== 1) {
            throw new \InvalidArgumentException('Недопустимый идентификатор загрузки');
        }

        $this->stage = self::STAGE_CAPSULE;
        $this->activeBatchId = $batchId;
        $this->skippedAt = null;
        $this->touch();
    }

    public function markReadyForOutfit(): void
    {
        if ($this->stage !== self::STAGE_CAPSULE) {
            throw new \DomainException('Сначала добавьте первые вещи');
        }

        $this->stage = self::STAGE_OUTFIT;
        $this->activeBatchId = null;
        $this->skippedAt = null;
        $this->touch();
    }

    public function skip(): void
    {
        if ($this->isCompleted()) {
            return;
        }

        $this->skippedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function resume(): void
    {
        if ($this->isCompleted() || $this->skippedAt === null) {
            return;
        }

        $this->skippedAt = null;
        $this->touch();
    }

    public function complete(): void
    {
        if ($this->isCompleted()) {
            return;
        }

        $this->stage = self::STAGE_COMPLETED;
        $this->activeBatchId = null;
        $this->skippedAt = null;
        $this->completedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function isSkipped(): bool { return $this->skippedAt !== null; }
    public function isCompleted(): bool { return $this->stage === self::STAGE_COMPLETED; }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
