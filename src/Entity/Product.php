<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;
use Nevinny\AdminCoreBundle\Entity\Trait\DefaultFields;
use Nevinny\AdminCoreBundle\Entity\Trait\Owner;
use Nevinny\AdminCoreBundle\Entity\Trait\Status;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'brand_slug_unique', columns: ['brand_id', 'slug'])]
#[Vich\Uploadable]
class Product
{
    use DefaultFields, Created, Owner, Status;

    // Пол / аудитория
    public const GENDER_MEN = 'men';
    public const GENDER_WOMEN = 'women';
    public const GENDER_UNISEX = 'unisex';
    public const GENDER_KIDS = 'kids';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $uuid = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $slug = null;

    #[ORM\ManyToOne(inversedBy: 'products')]
    private ?Brand $brand = null;

    #[ORM\ManyToOne]
    private ?ProductCategory $category = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $gender = null;

    // Краткое описание (для карточки в каталоге)
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $anons = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    // Стили (streetwear, casual, sport...) — из справочника BrandStyle
    /**
     * @var Collection<int, BrandStyle>
     */
    #[ORM\ManyToMany(targetEntity: BrandStyle::class)]
    #[ORM\JoinTable(name: 'product_style')]
    private Collection $styles;

    /**
     * @var Collection<int, ProductVariant>
     */
    #[ORM\OneToMany(targetEntity: ProductVariant::class, mappedBy: 'product', cascade: ['persist'], orphanRemoval: true)]
    private Collection $variants;

    /**
     * @var Collection<int, ProductImage>
     */
    #[ORM\OneToMany(targetEntity: ProductImage::class, mappedBy: 'product', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sort' => 'ASC'])]
    private Collection $productImages;

    // SEO
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitle = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $metaDescription = null;

    // Совместимость с legacy-полями
    #[ORM\Column(nullable: true)]
    private ?float $price = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    // Характеристики товара
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $material = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $composition = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $careInstructions = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $countryOfOrigin = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $manufacturer = null;

    public function __construct()
    {
        $this->styles = new ArrayCollection();
        $this->variants = new ArrayCollection();
        $this->productImages = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getUuid(): ?string { return $this->uuid; }

    public function setUuid(string $uuid): static
    {
        $this->uuid = $uuid;
        return $this;
    }

    #[ORM\PrePersist]
    public function generateUuid(): void
    {
        if ($this->uuid === null) {
            $this->uuid = Uuid::v4()->toRfc4122();
        }
    }

    public function getTitle(): ?string { return $this->title; }

    public function setTitle(?string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getSlug(): ?string { return $this->slug; }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getBrand(): ?Brand { return $this->brand; }

    public function setBrand(?Brand $brand): static
    {
        $this->brand = $brand;
        return $this;
    }

    public function getCategory(): ?ProductCategory { return $this->category; }

    public function setCategory(?ProductCategory $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getGender(): ?string { return $this->gender; }

    public function setGender(?string $gender): static
    {
        $this->gender = $gender;
        return $this;
    }

    public function getAnons(): ?string { return $this->anons; }

    public function setAnons(?string $anons): static
    {
        $this->anons = $anons;
        return $this;
    }

    public function getDescription(): ?string { return $this->description; }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getStyles(): Collection { return $this->styles; }

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

    public function getVariants(): Collection { return $this->variants; }

    public function addVariant(ProductVariant $variant): static
    {
        if (!$this->variants->contains($variant)) {
            $this->variants->add($variant);
            $variant->setProduct($this);
        }
        return $this;
    }

    public function removeVariant(ProductVariant $variant): static
    {
        $this->variants->removeElement($variant);
        return $this;
    }

    public function getProductImages(): Collection { return $this->productImages; }

    public function getMainImage(): ?ProductImage
    {
        foreach ($this->productImages as $image) {
            if ($image->isMain()) {
                return $image;
            }
        }
        return $this->productImages->first() ?: null;
    }

    public function getMetaTitle(): ?string { return $this->metaTitle; }

    public function setMetaTitle(?string $metaTitle): static
    {
        $this->metaTitle = $metaTitle;
        return $this;
    }

    public function getMetaDescription(): ?string { return $this->metaDescription; }

    public function setMetaDescription(?string $metaDescription): static
    {
        $this->metaDescription = $metaDescription;
        return $this;
    }

    // Legacy
    public function getPrice(): ?float { return $this->price; }
    public function setPrice(?float $price): static { $this->price = $price; return $this; }

    public function getImage(): ?string { return $this->image; }
    public function setImage(?string $image): static { $this->image = $image; return $this; }

    public function getMaterial(): ?string { return $this->material; }
    public function setMaterial(?string $material): static { $this->material = $material; return $this; }

    public function getComposition(): ?string { return $this->composition; }
    public function setComposition(?string $composition): static { $this->composition = $composition; return $this; }

    public function getCareInstructions(): ?string { return $this->careInstructions; }
    public function setCareInstructions(?string $careInstructions): static { $this->careInstructions = $careInstructions; return $this; }

    public function getCountryOfOrigin(): ?string { return $this->countryOfOrigin; }
    public function setCountryOfOrigin(?string $countryOfOrigin): static { $this->countryOfOrigin = $countryOfOrigin; return $this; }

    public function getManufacturer(): ?string { return $this->manufacturer; }
    public function setManufacturer(?string $manufacturer): static { $this->manufacturer = $manufacturer; return $this; }

    public function getMinPrice(): ?float
    {
        $prices = $this->variants
            ->filter(fn(ProductVariant $v) => $v->getStatus() === 'active' && $v->isInStock())
            ->map(fn(ProductVariant $v) => $v->getPriceFloat())
            ->toArray();

        return $prices ? min($prices) : null;
    }

    public function isInStock(): bool
    {
        foreach ($this->variants as $variant) {
            if ($variant->isInStock() && $variant->getStatus() === 'active') {
                return true;
            }
        }
        return false;
    }

    public function __toString(): string { return $this->title ?? ''; }
}
