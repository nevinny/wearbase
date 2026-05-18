<?php

namespace App\Entity;

use App\Repository\BrandImageRepository;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;
use Nevinny\AdminCoreBundle\Entity\Trait\DefaultFields;
use Nevinny\AdminCoreBundle\Entity\Trait\Owner;
use Nevinny\AdminCoreBundle\Entity\Trait\Status;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[Vich\Uploadable]
#[ORM\Entity(repositoryClass: BrandImageRepository::class)]
class BrandImage
{
    use DefaultFields, Created, Owner, Status;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'images')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Brand $brand = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $preview = null;

    #[Vich\UploadableField(mapping: 'brand_image_preview', fileNameProperty: 'preview')]
    private ?File $previewFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    #[Vich\UploadableField(mapping: 'brand_image_image', fileNameProperty: 'image')]
    private ?File $imageFile = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBrand(): ?Brand
    {
        return $this->brand;
    }

    public function setBrand(?Brand $brand): static
    {
        $this->brand = $brand;

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
}
