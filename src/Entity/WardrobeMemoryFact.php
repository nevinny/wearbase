<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeMemoryFactRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WardrobeMemoryFactRepository::class)]
#[ORM\Table(name: 'wardrobe_memory_fact')]
#[ORM\UniqueConstraint(name: 'uniq_wardrobe_memory_source', columns: ['profile_subject_id', 'source_type', 'source_id'])]
#[ORM\Index(name: 'idx_wardrobe_memory_subject_active', columns: ['profile_subject_id', 'deleted_at'])]
class WardrobeMemoryFact
{
    public const SOURCE_WEAR = 'wear';
    public const SOURCE_FITTING = 'fitting';
    public const SIGNAL_SELF = 'self';
    public const SIGNAL_PARENT_OBSERVED = 'parent_observed';
    public const SIGNAL_CHILD_CONFIRMED = 'child_confirmed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $profileSubject;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $actor;

    #[ORM\Column(length: 12)]
    private string $sourceType;

    #[ORM\Column]
    private int $sourceId;

    #[ORM\Column(length: 20)]
    private string $signalSource;

    #[ORM\Column(length: 500)]
    private string $fact;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $deletedByUser = false;

    public function __construct(User $subject, User $actor, string $sourceType, int $sourceId, string $signalSource, string $fact)
    {
        if (!in_array($sourceType, [self::SOURCE_WEAR, self::SOURCE_FITTING], true)
            || !in_array($signalSource, [self::SIGNAL_SELF, self::SIGNAL_PARENT_OBSERVED, self::SIGNAL_CHILD_CONFIRMED], true)
            || $sourceId < 1
        ) {
            throw new \InvalidArgumentException('Некорректный источник факта');
        }
        $this->profileSubject = $subject;
        $this->actor = $actor;
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->signalSource = $signalSource;
        $this->fact = self::clean($fact);
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProfileSubject(): User { return $this->profileSubject; }
    public function getActor(): User { return $this->actor; }
    public function getSourceType(): string { return $this->sourceType; }
    public function getSourceId(): int { return $this->sourceId; }
    public function getSignalSource(): string { return $this->signalSource; }
    public function getFact(): string { return $this->fact; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getEditedAt(): ?\DateTimeImmutable { return $this->editedAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function isDeleted(): bool { return $this->deletedAt !== null; }
    public function isDeletedByUser(): bool { return $this->deletedByUser; }

    public function refresh(string $fact): void
    {
        if ($this->deletedByUser) {
            return;
        }
        if ($this->editedAt === null) {
            $this->fact = self::clean($fact);
            $this->updatedAt = new \DateTimeImmutable();
        }
        $this->deletedAt = null;
    }

    public function edit(string $fact): void
    {
        $this->fact = self::clean($fact);
        $this->editedAt = $this->updatedAt = new \DateTimeImmutable();
        $this->deletedAt = null;
        $this->deletedByUser = false;
    }

    public function delete(bool $byUser = true): void
    {
        $this->deletedAt = $this->updatedAt = new \DateTimeImmutable();
        $this->deletedByUser = $this->deletedByUser || $byUser;
        if ($byUser) {
            $this->fact = '[deleted]';
        }
    }

    private static function clean(string $fact): string
    {
        $fact = trim((string) preg_replace('/\s+/u', ' ', $fact));
        if ($fact === '' || mb_strlen($fact) > 500) {
            throw new \InvalidArgumentException('Факт должен содержать от 1 до 500 символов');
        }
        return $fact;
    }
}
