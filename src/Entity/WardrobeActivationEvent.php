<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeActivationEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WardrobeActivationEventRepository::class)]
#[ORM\Table(name: 'wardrobe_activation_event')]
#[ORM\UniqueConstraint(name: 'uniq_wardrobe_activation_dedup', columns: ['profile_subject_id', 'event_type', 'dedup_key'])]
class WardrobeActivationEvent
{
    public const ONBOARDING_STARTED = 'onboarding_started';
    public const FIRST_ITEM_ADDED = 'first_item_added';
    public const FIRST_OUTFIT_CREATED = 'first_outfit_created';
    public const REPEAT_WEAR_RECORDED = 'repeat_wear_recorded';
    public const BATCH_RECOGNITION_STARTED = 'batch_recognition_started';
    public const BATCH_RECOGNITION_COMPLETED = 'batch_recognition_completed';
    public const DRAFT_ACCEPTED = 'draft_accepted';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $profileSubject;

    #[ORM\Column(length: 32)]
    private string $eventType;

    #[ORM\Column(length: 64)]
    private string $dedupKey;

    /** @var array<string, bool|string> */
    #[ORM\Column(type: 'json')]
    private array $metadata;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /** @param array<string, bool|string> $metadata */
    public function __construct(User $profileSubject, string $eventType, string $dedupKey, array $metadata, ?\DateTimeImmutable $occurredAt = null)
    {
        $this->profileSubject = $profileSubject;
        $this->eventType = $eventType;
        $this->dedupKey = $dedupKey;
        $this->metadata = $metadata;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProfileSubject(): User { return $this->profileSubject; }
    public function getEventType(): string { return $this->eventType; }
    public function getDedupKey(): string { return $this->dedupKey; }
    /** @return array<string, bool|string> */
    public function getMetadata(): array { return $this->metadata; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
}
