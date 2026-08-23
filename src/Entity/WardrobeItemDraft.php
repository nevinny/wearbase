<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeItemDraftRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * Стейджинг распознанных, но ещё не подтверждённых карточек гардероба (авто-инжест
 * фото). НЕ WardrobeItem: временная запись — physical DELETE допустим (не подпадает
 * под правило soft-delete, т.к. не пользовательские данные о вещи, а черновик распознавания).
 */
#[ORM\Entity(repositoryClass: WardrobeItemDraftRepository::class)]
#[ORM\Table(name: 'wardrobe_item_draft')]
#[ORM\Index(name: 'idx_wardrobe_draft_user_batch', columns: ['user_id', 'batch_id'])]
#[ORM\Index(name: 'idx_wardrobe_draft_status', columns: ['status'])]
#[Vich\Uploadable]
class WardrobeItemDraft
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RECOGNIZED = 'recognized';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $actor = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?WardrobeItem $acceptedItem = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(name: 'batch_id', length: 36)]
    private ?string $batchId = null;

    #[ORM\Column(length: 12)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 4, nullable: true)]
    private ?string $confidence = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $size = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'ai_raw', type: Types::JSON, nullable: true)]
    private ?array $aiRaw = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;

    #[Vich\UploadableField(mapping: 'wardrobe_draft_photo', fileNameProperty: 'photo')]
    private ?File $photoFile = null;

    #[ORM\Column]
    private \DateTime $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->status = self::STATUS_PENDING;
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        $this->actor ??= $user;
        return $this;
    }

    public function getProfileSubject(): ?User { return $this->user; }
    public function setProfileSubject(User $subject): static { $this->user = $subject; return $this; }
    public function getActor(): ?User { return $this->actor; }
    public function setActor(User $actor): static { $this->actor = $actor; return $this; }
    public function getAcceptedItem(): ?WardrobeItem { return $this->acceptedItem; }
    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }

    public function accept(WardrobeItem $item): void
    {
        if ($this->status === self::STATUS_ACCEPTED) {
            if ($this->acceptedItem?->getId() !== $item->getId()) {
                throw new \DomainException('Черновик уже принят как другая вещь');
            }
            return;
        }
        if ($this->status !== self::STATUS_RECOGNIZED && $this->status !== self::STATUS_FAILED) {
            throw new \DomainException('Черновик ещё не готов к подтверждению');
        }

        $this->status = self::STATUS_ACCEPTED;
        $this->acceptedItem = $item;
        $this->acceptedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTime();
    }

    public function getBatchId(): ?string { return $this->batchId; }

    public function setBatchId(string $batchId): static
    {
        $this->batchId = $batchId;
        return $this;
    }

    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getConfidence(): ?string { return $this->confidence; }

    public function setConfidence(?string $confidence): static
    {
        $this->confidence = $confidence;
        return $this;
    }

    public function getCategory(): ?string { return $this->category; }

    public function setCategory(?string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getName(): ?string { return $this->name; }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getSize(): ?string { return $this->size; }

    public function setSize(?string $size): static
    {
        $this->size = $size;
        return $this;
    }

    public function getNotes(): ?string { return $this->notes; }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function getAiRaw(): ?array { return $this->aiRaw; }

    public function setAiRaw(?array $aiRaw): static
    {
        $this->aiRaw = $aiRaw;
        return $this;
    }

    public function getError(): ?string { return $this->error; }

    public function setError(?string $error): static
    {
        $this->error = $error;
        return $this;
    }

    public function getPhoto(): ?string { return $this->photo; }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;
        return $this;
    }

    public function setPhotoFile(?File $photoFile = null): void
    {
        $this->photoFile = $photoFile;
        if ($photoFile !== null) {
            // Иначе Vich не увидит изменения сущности и не сохранит файл
            $this->updatedAt = new \DateTime();
        }
    }

    public function getPhotoFile(): ?File { return $this->photoFile; }

    public function getCreatedAt(): \DateTime { return $this->createdAt; }

    public function getUpdatedAt(): ?\DateTime { return $this->updatedAt; }

    public function setUpdatedAt(?\DateTime $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
