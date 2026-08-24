<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeActivationEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WardrobeActivationEventRepository::class)]
#[ORM\Table(name: 'wardrobe_activation_event')]
#[ORM\UniqueConstraint(name: 'uniq_wardrobe_activation_milestone', columns: ['profile_subject_id', 'event_type'])]
final class WardrobeActivationEvent
{
    public const ONBOARDING_STARTED = 'onboarding_started';
    public const FIRST_ITEM_ADDED = 'first_item_added';
    public const FIRST_OUTFIT_CREATED = 'first_outfit_created';
    public const REPEAT_WEAR_RECORDED = 'repeat_wear_recorded';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $profileSubject;

    #[ORM\Column(length: 32)]
    private string $eventType;

    /** @var array{actorKind:string,entryPoint:string} */
    #[ORM\Column(type: 'json')]
    private array $metadata;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /** @param array{actorKind:string,entryPoint:string} $metadata */
    public function __construct(User $profileSubject, string $eventType, array $metadata, ?\DateTimeImmutable $occurredAt = null)
    {
        $this->profileSubject = $profileSubject;
        $this->eventType = $eventType;
        $this->metadata = $metadata;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProfileSubject(): User { return $this->profileSubject; }
    public function getEventType(): string { return $this->eventType; }
    /** @return array{actorKind:string,entryPoint:string} */
    public function getMetadata(): array { return $this->metadata; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
}
