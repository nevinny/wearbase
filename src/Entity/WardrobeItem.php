<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: WardrobeItemRepository::class)]
#[ORM\Table(name: 'wardrobe_item')]
#[ORM\UniqueConstraint(name: 'uniq_wardrobe_user_item_no', columns: ['user_id', 'item_no'])]
#[ORM\Index(name: 'idx_wardrobe_user_deleted', columns: ['user_id', 'deleted_at'])]
#[Vich\Uploadable]
class WardrobeItem
{
    public const LOVE_YES = 'yes';
    public const LOVE_NO = 'no';
    public const LOVE_UNKNOWN = 'unknown';

    public const SOURCE_WEB = 'web';
    public const SOURCE_TELEGRAM = 'telegram';
    public const SOURCE_IMPORT = 'import';

    public const SUGGESTED_CATEGORIES = [
        'Футболки',
        'Майки и топы',
        'Косухи',
        'Платья',
        'Жилеты',
        'Худи',
        'Шапки',
        'Зимние кроссовки',
        'Ботинки',
        'Ботильоны',
        'Сапоги',
        'Туфли',
        'Ремни',
        'Ремни для сумок',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'wardrobeItems')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    // Сквозной номер вещи внутри гардероба пользователя (уникален per-user, включая удалённые)
    #[ORM\Column]
    private int $itemNo = 0;

    #[ORM\Column(length: 100)]
    private ?string $category = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $size = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $purchasedAt = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $productUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $purchaseReason = null;

    // Любовь с первого взгляда: LOVE_YES / LOVE_NO / LOVE_UNKNOWN
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $loveAtFirstSight = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pros = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $cons = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $verdict = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;

    #[Vich\UploadableField(mapping: 'wardrobe_item_photo', fileNameProperty: 'photo')]
    private ?File $photoFile = null;

    // Канал добавления: SOURCE_WEB / SOURCE_TELEGRAM / SOURCE_IMPORT
    #[ORM\Column(length: 20, options: ['default' => self::SOURCE_WEB])]
    private string $source = self::SOURCE_WEB;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getItemNo(): int { return $this->itemNo; }

    public function setItemNo(int $itemNo): static
    {
        $this->itemNo = $itemNo;
        return $this;
    }

    public function getDisplayNumber(): string
    {
        return sprintf('#%04d', $this->itemNo);
    }

    public function getCategory(): ?string { return $this->category; }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getName(): ?string { return $this->name; }

    public function setName(string $name): static
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

    public function getPrice(): ?string { return $this->price; }

    public function setPrice(?string $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getPurchasedAt(): ?\DateTimeImmutable { return $this->purchasedAt; }

    public function setPurchasedAt(?\DateTimeImmutable $purchasedAt): static
    {
        $this->purchasedAt = $purchasedAt;
        return $this;
    }

    public function getProductUrl(): ?string { return $this->productUrl; }

    public function setProductUrl(?string $productUrl): static
    {
        $this->productUrl = $productUrl;
        return $this;
    }

    public function getNotes(): ?string { return $this->notes; }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function getPurchaseReason(): ?string { return $this->purchaseReason; }

    public function setPurchaseReason(?string $purchaseReason): static
    {
        $this->purchaseReason = $purchaseReason;
        return $this;
    }

    public function getLoveAtFirstSight(): ?string { return $this->loveAtFirstSight; }

    public function setLoveAtFirstSight(?string $loveAtFirstSight): static
    {
        $this->loveAtFirstSight = $loveAtFirstSight;
        return $this;
    }

    public function getPros(): ?string { return $this->pros; }

    public function setPros(?string $pros): static
    {
        $this->pros = $pros;
        return $this;
    }

    public function getCons(): ?string { return $this->cons; }

    public function setCons(?string $cons): static
    {
        $this->cons = $cons;
        return $this;
    }

    public function getVerdict(): ?string { return $this->verdict; }

    public function setVerdict(?string $verdict): static
    {
        $this->verdict = $verdict;
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
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function getPhotoFile(): ?File { return $this->photoFile; }

    public function getSource(): string { return $this->source; }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    // \DateTimeInterface, а не Immutable: EntityUserListener::preUpdate() передаёт \DateTime
    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt === null ? null : \DateTimeImmutable::createFromInterface($updatedAt);
        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function softDelete(): void
    {
        $this->deletedAt = new \DateTimeImmutable();
    }
}
