<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity]
#[ORM\Table(name: 'wardrobe_item_photo')]
#[ORM\Index(name: 'idx_wardrobe_photo_item_deleted', columns: ['item_id', 'deleted_at'])]
#[Vich\Uploadable]
class WardrobeItemPhoto
{
    public const TYPE_COVER = 'cover';
    public const TYPE_PRODUCT = 'product';
    public const TYPE_BACK = 'back';
    public const TYPE_DETAIL = 'detail';
    public const TYPE_LABEL = 'label';
    public const TYPE_CARE = 'care';
    public const TYPE_RECEIPT = 'receipt';

    public const SOURCE_UPLOAD = 'user_upload';
    public const SOURCE_MARKETPLACE = 'marketplace';
    public const SOURCE_IMPORT = 'import';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'photos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?WardrobeItem $item = null;

    #[ORM\Column(length: 255)]
    private ?string $filePath = null;

    #[Vich\UploadableField(mapping: 'wardrobe_item_photo', fileNameProperty: 'filePath')]
    private ?File $file = null;

    #[ORM\Column(length: 20, options: ['default' => self::TYPE_PRODUCT])]
    private string $photoType = self::TYPE_PRODUCT;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(length: 20, options: ['default' => self::SOURCE_UPLOAD])]
    private string $source = self::SOURCE_UPLOAD;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalFilename = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isCover = false;

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
    public function getItem(): ?WardrobeItem { return $this->item; }
    public function setItem(?WardrobeItem $item): static { $this->item = $item; return $this; }
    public function getFilePath(): ?string { return $this->filePath; }
    public function setFilePath(?string $filePath): static { $this->filePath = $filePath; return $this; }
    public function getFile(): ?File { return $this->file; }

    public function setFile(?File $file): static
    {
        $this->file = $file;
        if ($file !== null) {
            $this->updatedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function getPhotoType(): string { return $this->photoType; }
    public function setPhotoType(string $photoType): static { $this->photoType = $photoType; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): static { $this->sortOrder = $sortOrder; return $this; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $source): static { $this->source = $source; return $this; }
    public function getOriginalFilename(): ?string { return $this->originalFilename; }
    public function setOriginalFilename(?string $name): static { $this->originalFilename = $name; return $this; }
    public function getMimeType(): ?string { return $this->mimeType; }
    public function setMimeType(?string $mimeType): static { $this->mimeType = $mimeType; return $this; }
    public function getFileSize(): ?int { return $this->fileSize; }
    public function setFileSize(?int $fileSize): static { $this->fileSize = $fileSize; return $this; }
    public function isCover(): bool { return $this->isCover; }
    public function setIsCover(bool $isCover): static { $this->isCover = $isCover; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function isDeleted(): bool { return $this->deletedAt !== null; }
    public function softDelete(): void { $this->deletedAt = new \DateTimeImmutable(); }
}
