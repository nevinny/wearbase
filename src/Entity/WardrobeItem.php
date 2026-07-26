<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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
    public const COMPLETION_DRAFT = 'draft';
    public const COMPLETION_BASIC = 'basic';
    public const COMPLETION_COMPLETE = 'complete';
    public const COMPLETION_LABELS = [
        self::COMPLETION_DRAFT => 'Черновик',
        self::COMPLETION_BASIC => 'Основное заполнено',
        self::COMPLETION_COMPLETE => 'Заполнено полностью',
    ];

    public const ITEM_ACTIVE = 'active';
    public const ITEM_REPAIR = 'repair';
    public const ITEM_ARCHIVED = 'archived';
    public const ITEM_SOLD = 'sold';
    public const ITEM_DONATED = 'donated';
    public const ITEM_TRANSFERRED = 'transferred';
    public const ITEM_LOST = 'lost';
    public const ITEM_LABELS = [
        self::ITEM_ACTIVE => 'Активна',
        self::ITEM_REPAIR => 'В ремонте',
        self::ITEM_ARCHIVED => 'В архиве',
        self::ITEM_SOLD => 'Продана',
        self::ITEM_DONATED => 'Подарена',
        self::ITEM_TRANSFERRED => 'Передана',
        self::ITEM_LOST => 'Потеряна',
    ];

    public const LOVE_YES = 'yes';
    public const LOVE_NO = 'no';
    public const LOVE_UNKNOWN = 'unknown';

    public const SOURCE_WEB = 'web';
    public const SOURCE_TELEGRAM = 'telegram';
    public const SOURCE_IMPORT = 'import';

    // Статус носки (семейный гардероб). GIVEN_AWAY — терминальный «отдана из семьи»,
    // это НЕ deleted_at: вещь остаётся в истории, но уходит из активных выборок.
    public const WEAR_ACTIVE = 'active';
    public const WEAR_RESERVE = 'reserve';
    public const WEAR_OUTGROWN = 'outgrown';
    public const WEAR_GIVEN_AWAY = 'given_away';

    public const WEAR_LABELS = [
        self::WEAR_ACTIVE     => 'Носится',
        self::WEAR_RESERVE    => 'На вырост',
        self::WEAR_OUTGROWN   => 'Мала — ждёт передачи',
        self::WEAR_GIVEN_AWAY => 'Отдана из семьи',
    ];

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

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Wardrobe $wardrobe = null;

    // Сквозной номер вещи внутри гардероба пользователя (уникален per-user, включая удалённые)
    #[ORM\Column]
    private int $itemNo = 0;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $category = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?WardrobeCategory $categoryRef = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customBrandName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $colorName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $materialText = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $countryOfOrigin = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $season = null;

    /** @var Collection<int, BrandStyle> */
    #[ORM\ManyToMany(targetEntity: BrandStyle::class)]
    #[ORM\JoinTable(name: 'wardrobe_item_style')]
    private Collection $styles;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $careText = null;

    #[ORM\Column(length: 12, options: ['default' => self::COMPLETION_DRAFT])]
    private string $completionStatus = self::COMPLETION_DRAFT;

    #[ORM\Column(length: 12, options: ['default' => self::ITEM_ACTIVE])]
    private string $itemStatus = self::ITEM_ACTIVE;

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

    /** @var Collection<int, WardrobeItemPhoto> */
    #[ORM\OneToMany(mappedBy: 'item', targetEntity: WardrobeItemPhoto::class, cascade: ['persist'])]
    #[ORM\OrderBy(['isCover' => 'DESC', 'sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $photos;

    // Канал добавления: SOURCE_WEB / SOURCE_TELEGRAM / SOURCE_IMPORT
    #[ORM\Column(length: 20, options: ['default' => self::SOURCE_WEB])]
    private string $source = self::SOURCE_WEB;

    // Статус носки: WEAR_ACTIVE / WEAR_RESERVE / WEAR_OUTGROWN / WEAR_GIVEN_AWAY
    #[ORM\Column(length: 12, options: ['default' => self::WEAR_ACTIVE])]
    private string $wearStatus = self::WEAR_ACTIVE;

    // Кому вещь принадлежала изначально; immutable при передачах внутри семьи
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $originalOwner = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->photos = new ArrayCollection();
        $this->styles = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getWardrobe(): ?Wardrobe { return $this->wardrobe; }

    public function setWardrobe(?Wardrobe $wardrobe): static
    {
        $this->wardrobe = $wardrobe;
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

    public function setCategory(?string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getCategoryRef(): ?WardrobeCategory { return $this->categoryRef; }

    public function setCategoryRef(?WardrobeCategory $categoryRef): static
    {
        $this->categoryRef = $categoryRef;
        $this->category = $categoryRef?->getName();
        return $this;
    }

    public function getName(): ?string { return $this->name; }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getCustomBrandName(): ?string { return $this->customBrandName; }

    public function setCustomBrandName(?string $customBrandName): static
    {
        $this->customBrandName = $customBrandName;
        return $this;
    }

    public function getColorName(): ?string { return $this->colorName; }

    public function setColorName(?string $colorName): static
    {
        $this->colorName = $colorName;
        return $this;
    }

    public function getMaterialText(): ?string { return $this->materialText; }

    public function setMaterialText(?string $materialText): static
    {
        $this->materialText = $materialText;
        return $this;
    }

    public function getCountryOfOrigin(): ?string { return $this->countryOfOrigin; }

    public function setCountryOfOrigin(?string $countryOfOrigin): static
    {
        $this->countryOfOrigin = $countryOfOrigin;
        return $this;
    }

    public function getSeason(): ?string { return $this->season; }

    public function setSeason(?string $season): static
    {
        $this->season = $season;
        return $this;
    }

    /** @return Collection<int, BrandStyle> */
    public function getStyles(): Collection { return $this->styles; }

    /** @return string[] */
    public function getStyleLabels(): array
    {
        return array_values(array_filter(array_map(
            static fn (BrandStyle $style): ?string => $style->getTitle(),
            $this->styles->toArray(),
        )));
    }

    public function addStyle(BrandStyle $style): static
    {
        if (!$this->styles->contains($style)) {
            $this->styles->add($style);
        }
        return $this;
    }

    public function removeStyle(BrandStyle $style): static
    {
        $this->styles->removeElement($style);
        return $this;
    }

    public function getCareText(): ?string { return $this->careText; }

    public function setCareText(?string $careText): static
    {
        $this->careText = $careText;
        return $this;
    }

    public function getCompletionStatus(): string { return $this->completionStatus; }

    public function getCompletionStatusLabel(): string
    {
        return self::COMPLETION_LABELS[$this->completionStatus] ?? $this->completionStatus;
    }

    public function setCompletionStatus(string $completionStatus): static
    {
        $this->completionStatus = $completionStatus;
        return $this;
    }

    public function getItemStatus(): string { return $this->itemStatus; }

    public function setItemStatus(string $itemStatus): static
    {
        $this->itemStatus = $itemStatus;
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

    /** @return Collection<int, WardrobeItemPhoto> */
    public function getPhotos(): Collection { return $this->photos; }

    /** @return WardrobeItemPhoto[] */
    public function getActivePhotos(): array
    {
        return $this->photos
            ->filter(static fn (WardrobeItemPhoto $photo): bool => !$photo->isDeleted())
            ->toArray();
    }

    public function getCoverPhoto(): ?WardrobeItemPhoto
    {
        foreach ($this->getActivePhotos() as $photo) {
            if ($photo->isCover()) {
                return $photo;
            }
        }
        return $this->getActivePhotos()[0] ?? null;
    }

    public function addPhoto(WardrobeItemPhoto $photo): static
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
            $photo->setItem($this);
        }
        return $this;
    }

    public function getSource(): string { return $this->source; }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getWearStatus(): string { return $this->wearStatus; }

    public function setWearStatus(string $wearStatus): static
    {
        $this->wearStatus = $wearStatus;
        return $this;
    }

    public function getWearStatusLabel(): string
    {
        return self::WEAR_LABELS[$this->wearStatus] ?? $this->wearStatus;
    }

    public function getOriginalOwner(): ?User { return $this->originalOwner; }

    public function setOriginalOwner(?User $originalOwner): static
    {
        $this->originalOwner = $originalOwner;
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
