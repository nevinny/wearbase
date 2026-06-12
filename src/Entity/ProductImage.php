<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductImageRepository;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;
use Nevinny\AdminCoreBundle\Entity\Trait\DefaultFields;
use Nevinny\AdminCoreBundle\Entity\Trait\Owner;
use Nevinny\AdminCoreBundle\Entity\Trait\Status;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[Vich\Uploadable]
#[ORM\Entity(repositoryClass: ProductImageRepository::class)]
class ProductImage
{
    use DefaultFields, Created, Owner, Status;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'productImages')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    // Опционально: фото привязано к конкретному варианту (напр. фото белой версии)
    #[ORM\ManyToOne]
    private ?ProductVariant $variant = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $preview = null;

    #[Vich\UploadableField(mapping: 'product_image_preview', fileNameProperty: 'preview')]
    private ?File $previewFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    #[Vich\UploadableField(mapping: 'product_image_image', fileNameProperty: 'image')]
    private ?File $imageFile = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $sort = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $isMain = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;
        return $this;
    }

    public function getVariant(): ?ProductVariant
    {
        return $this->variant;
    }

    public function setVariant(?ProductVariant $variant): static
    {
        $this->variant = $variant;
        return $this;
    }

    public function getPreview(): ?string
    {
        return $this->preview;
    }

    public function setPreview(?string $preview): static
    {
        $this->preview = $preview;
        return $this;
    }

    public function getPreviewFile(): ?File
    {
        return $this->previewFile;
    }

    public function setPreviewFile(?File $file = null): void
    {
        $this->previewFile = $file;

        if ($file !== null) {
            $this->updated_at = new \DateTime();
        }
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;
        return $this;
    }

    public function getImageFile(): ?File
    {
        return $this->imageFile;
    }

    public function setImageFile(?File $file = null): void
    {
        $this->imageFile = $file;

        if ($file !== null) {
            $this->updated_at = new \DateTime();
        }
    }

    public function getSort(): int
    {
        return $this->sort;
    }

    public function setSort(int $sort): static
    {
        $this->sort = $sort;
        return $this;
    }

    public function isMain(): bool
    {
        return $this->isMain;
    }

    public function setIsMain(bool $isMain): static
    {
        $this->isMain = $isMain;
        return $this;
    }
}
