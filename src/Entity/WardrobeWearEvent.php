<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeWearEventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: WardrobeWearEventRepository::class)]
#[ORM\Table(name: 'wardrobe_wear_event')]
#[ORM\Index(columns: ['profile_subject_id', 'worn_on'], name: 'idx_wear_subject_date')]
#[ORM\UniqueConstraint(name: 'uniq_wear_outfit_day', columns: ['profile_subject_id', 'source_outfit_id', 'worn_on', 'type'])]
#[Vich\Uploadable]
class WardrobeWearEvent
{
    public const TYPE_WORN = 'worn';
    public const TYPE_FITTING = 'fitting';
    public const TYPE_PLANNED = 'planned';
    public const TYPES = [self::TYPE_WORN, self::TYPE_FITTING, self::TYPE_PLANNED];
    public const STATUS_REVIEW = 'review';
    public const STATUS_CONFIRMED = 'confirmed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $profileSubject;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $actor;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?WardrobeOutfit $sourceOutfit = null;
    #[ORM\Column(length: 12)]
    private string $type;
    #[ORM\Column(length: 12)]
    private string $status = self::STATUS_REVIEW;
    #[ORM\Column]
    private \DateTimeImmutable $wornOn;
    #[ORM\Column(length: 20)]
    private string $signalSource;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $occasion = null;
    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $comment = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;
    #[Vich\UploadableField(mapping: 'wardrobe_wear_photo', fileNameProperty: 'photo')]
    private ?File $photoFile = null;
    /** @var Collection<int, WardrobeWearEventItem> */
    #[ORM\OneToMany(mappedBy: 'event', targetEntity: WardrobeWearEventItem::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $comfort = null;
    #[ORM\Column(nullable: true)]
    private ?bool $wantsRepeat = null;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $feedbackAt = null;

    public function __construct(User $profileSubject, User $actor, string $type, string $signalSource, ?\DateTimeImmutable $wornOn = null)
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Неизвестный тип события гардероба');
        }
        $this->profileSubject = $profileSubject;
        $this->actor = $actor;
        $this->type = $type;
        $this->signalSource = $signalSource;
        $this->wornOn = ($wornOn ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getProfileSubject(): User { return $this->profileSubject; }
    public function getActor(): User { return $this->actor; }
    public function getSourceOutfit(): ?WardrobeOutfit { return $this->sourceOutfit; }
    public function setSourceOutfit(?WardrobeOutfit $outfit): static { $this->sourceOutfit = $outfit; return $this; }
    public function getType(): string { return $this->type; }
    public function getStatus(): string { return $this->status; }
    public function getWornOn(): \DateTimeImmutable { return $this->wornOn; }
    public function getSignalSource(): string { return $this->signalSource; }
    public function getOccasion(): ?string { return $this->occasion; }
    public function setOccasion(?string $occasion): static { $this->occasion = $this->clean($occasion, 255); return $this; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): static { $this->comment = $this->clean($comment, 1000); return $this; }
    public function getPhoto(): ?string { return $this->photo; }
    public function setPhoto(?string $photo): static { $this->photo = $photo; return $this; }
    public function getPhotoFile(): ?File { return $this->photoFile; }
    public function setPhotoFile(?File $file): static { $this->photoFile = $file; return $this; }
    /** @return Collection<int, WardrobeWearEventItem> */
    public function getItems(): Collection { return $this->items; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }
    public function getComfort(): ?string { return $this->comfort; }
    public function wantsRepeat(): ?bool { return $this->wantsRepeat; }
    public function getFeedbackAt(): ?\DateTimeImmutable { return $this->feedbackAt; }
    public function isConfirmedWorn(): bool { return $this->status === self::STATUS_CONFIRMED && $this->type === self::TYPE_WORN; }

    public function changeType(string $type): void
    {
        if ($this->status !== self::STATUS_REVIEW || !in_array($type, self::TYPES, true)) {
            throw new \DomainException('Тип можно изменить только до подтверждения события');
        }
        $this->type = $type;
    }

    public function addItem(WardrobeItem $item, string $selectionSource = 'manual', ?string $confidence = null): void
    {
        foreach ($this->items as $eventItem) {
            if ($eventItem->getItem()->getId() === $item->getId()) {
                return;
            }
        }
        $this->items->add(new WardrobeWearEventItem($this, $item, $selectionSource, $confidence));
    }

    /** @param int[] $itemIds */
    public function confirm(array $itemIds): void
    {
        $selected = array_fill_keys(array_map('intval', $itemIds), true);
        foreach ($this->items->toArray() as $eventItem) {
            if (!isset($selected[$eventItem->getItem()->getId()])) {
                $this->items->removeElement($eventItem);
            } else {
                $eventItem->confirm();
                unset($selected[$eventItem->getItem()->getId()]);
            }
        }
        if ($this->items->isEmpty() || $selected !== []) {
            throw new \DomainException('Подтвердите хотя бы одну вещь из своего гардероба');
        }
        $this->status = self::STATUS_CONFIRMED;
        $this->confirmedAt = new \DateTimeImmutable();
    }

    public function addFeedback(string $comfort, ?bool $wantsRepeat, ?string $comment): void
    {
        if (!$this->isConfirmedWorn() || !in_array($comfort, ['comfortable', 'mixed', 'uncomfortable'], true)) {
            throw new \DomainException('Обратная связь доступна только для подтверждённой носки');
        }
        $this->comfort = $comfort;
        $this->wantsRepeat = $wantsRepeat;
        $this->setComment($comment);
        $this->feedbackAt = new \DateTimeImmutable();
    }

    /** @param int[] $itemIds */
    public function revise(string $type, array $itemIds): void
    {
        if ($this->status !== self::STATUS_CONFIRMED || !in_array($type, self::TYPES, true)) {
            throw new \DomainException('Изменить можно только подтверждённое событие');
        }
        $selected = array_fill_keys(array_map('intval', $itemIds), true);
        foreach ($this->items->toArray() as $eventItem) {
            if (!isset($selected[$eventItem->getItem()->getId()])) {
                $this->items->removeElement($eventItem);
            } else {
                $eventItem->confirm();
                unset($selected[$eventItem->getItem()->getId()]);
            }
        }
        if ($this->items->isEmpty() || $selected !== []) {
            throw new \DomainException('Подтвердите хотя бы одну вещь из своего гардероба');
        }
        $this->type = $type;
        $this->confirmedAt = new \DateTimeImmutable();
        $this->comfort = null;
        $this->wantsRepeat = null;
        $this->feedbackAt = null;
    }

    private function clean(?string $value, int $length): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
