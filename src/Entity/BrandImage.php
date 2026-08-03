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

    /** Вещь на человеке — приоритетный кадр для открытия галереи/Reels. */
    public const FRAME_PRODUCT_PERSON = 'product_person';
    /** Вещь без человека: раскладка/вешалка/предметка. */
    public const FRAME_PRODUCT_FLAT = 'product_flat';
    /** Лукбук-сцена/атмосфера, вещь не главное — уместна, но не в начале. */
    public const FRAME_SCENE = 'scene';
    /** Витрина/интерьер/текст/логотип — берётся только если без него не набрать MIN_SLIDES. */
    public const FRAME_OTHER = 'other';

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

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[Vich\UploadableField(mapping: 'brand_image_image', fileNameProperty: 'image')]
    private ?File $imageFile = null;

    /** product_person|product_flat|scene|other, NULL = ещё не классифицирован. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $frameKind = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $frameCheckedAt = null;

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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function getFrameKind(): ?string
    {
        return $this->frameKind;
    }

    public function setFrameKind(?string $frameKind): static
    {
        $this->frameKind = $frameKind;

        return $this;
    }

    public function getFrameCheckedAt(): ?\DateTimeInterface
    {
        return $this->frameCheckedAt;
    }

    public function setFrameCheckedAt(?\DateTimeInterface $frameCheckedAt): static
    {
        $this->frameCheckedAt = $frameCheckedAt;

        return $this;
    }
}
