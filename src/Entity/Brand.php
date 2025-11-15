<?php

namespace App\Entity;

use App\Repository\BrandRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;
use Nevinny\AdminCoreBundle\Entity\Trait\DefaultFields;
use Nevinny\AdminCoreBundle\Entity\Trait\Owner;
use Nevinny\AdminCoreBundle\Entity\Trait\Status;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Vich\UploaderBundle\Mapping\Annotation as Vich;
use Symfony\Component\HttpFoundation\File\File;

#[ORM\Entity(repositoryClass: BrandRepository::class)]
#[ORM\UniqueConstraint(
    name: "slug_unique_idx",
    columns: ["slug"]
)]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
#[UniqueEntity(fields: ["slug"], message: "Этот slug уже используется")]
class Brand
{
    use Created, Owner, Status;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    #[Vich\UploadableField(mapping: 'brand_logo', fileNameProperty: 'logo')]
    private ?File $logoFile = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $anons = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $websiteUrl = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $instagramUrl = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $telegramUrl = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $vkontakteUrl = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $youtubeUrl = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255)]
    private ?string $slug = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(nullable: true)]
    private ?int $parent = null;

    #[ORM\Column(options: ['default' => '0'])]
    private ?int $ord = 0;

    /**
     * @var Collection<int, Product>
     */
    #[ORM\OneToMany(targetEntity: Product::class, mappedBy: 'brand')]
    private Collection $products;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $city = null;

    /**
     * @var Collection<int, BrandSize>
     */
    #[ORM\ManyToMany(targetEntity: BrandSize::class, mappedBy: 'brands')]
    private Collection $sizes;

    /**
     * @var Collection<int, BrandStyle>
     */
    #[ORM\ManyToMany(targetEntity: BrandStyle::class, mappedBy: 'brands')]
    private Collection $styles;

    /**
     * @var Collection<int, BrandAudience>
     */
    #[ORM\ManyToMany(targetEntity: BrandAudience::class, mappedBy: 'brands')]
    private Collection $audiences;

    /**
     * @var Collection<int, BrandTier>
     */
    #[ORM\ManyToMany(targetEntity: BrandTier::class, mappedBy: 'brands')]
    private Collection $tiers;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $firstLetter = null;

    public function __construct()
    {
        $this->products = new ArrayCollection();
        $this->sizes = new ArrayCollection();
        $this->styles = new ArrayCollection();
        $this->audiences = new ArrayCollection();
        $this->tiers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->title ?? 'Без названия';
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;
        $this->updateFirstLetter();

        return $this;
    }

    public function getParent(): ?int
    {
        return $this->parent;
    }

    public function setParent(int $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getOrd(): ?int
    {
        return $this->ord;
    }

    public function setOrd(int $ord): static
    {
        $this->ord = $ord;

        return $this;
    }

    private function updateFirstLetter(): void
    {
        if (!$this->getTitle()) {
            $this->firstLetter = null;
            return;
        }

        $firstChar = mb_substr($this->getTitle(), 0, 1);
        $this->firstLetter = $this->normalizeFirstLetter($firstChar);
    }

    private function normalizeFirstLetter(string $letter): string
    {
        $upperLetter = mb_strtoupper($letter, 'UTF-8');

//        // Обработка специальных символов
//        if (is_numeric($letter)) {
//            return '0-9';
//        }
//
//        if (!preg_match('/[\p{L}\p{N}]/u', $letter)) {
//            return '#';
//        }

        return $upperLetter;
    }


    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;

        return $this;
    }

    public function getLogoFile(): ?File
    {
        return $this->logoFile;
    }

    public function setLogoFile(?File $file = null): void
    {
        $this->logoFile = $file;

        if ($file !== null) {
            $this->updated_at = new \DateTime();
        }
    }

    public function getAnons(): ?string
    {
        return $this->anons;
    }

    public function setAnons(?string $anons): static
    {
        $this->anons = $anons;

        return $this;
    }

    public function getWebsiteUrl(): ?string
    {
        return $this->websiteUrl;
    }

    public function setWebsiteUrl(?string $websiteUrl): static
    {
        $this->websiteUrl = $websiteUrl;

        return $this;
    }

    public function getInstagramUrl(): ?string
    {
        return $this->instagramUrl;
    }

    public function setInstagramUrl(?string $instagramUrl): static
    {
        $this->instagramUrl = $instagramUrl;

        return $this;
    }

    public function getTelegramUrl(): ?string
    {
        return $this->telegramUrl;
    }

    public function setTelegramUrl(?string $telegramUrl): static
    {
        $this->telegramUrl = $telegramUrl;

        return $this;
    }

    public function getVkontakteUrl(): ?string
    {
        return $this->vkontakteUrl;
    }

    public function setVkontakteUrl(?string $vkontakteUrl): static
    {
        $this->vkontakteUrl = $vkontakteUrl;

        return $this;
    }

    public function getYoutubeUrl(): ?string
    {
        return $this->youtubeUrl;
    }

    public function setYoutubeUrl(?string $youtubeUrl): static
    {
        $this->youtubeUrl = $youtubeUrl;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return Collection<int, Product>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): static
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
            $product->setBrand($this);
        }

        return $this;
    }

    public function removeProduct(Product $product): static
    {
        if ($this->products->removeElement($product)) {
            // set the owning side to null (unless already changed)
            if ($product->getBrand() === $this) {
                $product->setBrand(null);
            }
        }

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    /**
     * @return Collection<int, BrandSize>
     */
    public function getSizes(): Collection
    {
        return $this->sizes;
    }

    public function addBrandSize(BrandSize $brandSize): static
    {
        if (!$this->sizes->contains($brandSize)) {
            $this->sizes->add($brandSize);
            $brandSize->addBrand($this);
        }

        return $this;
    }

    public function removeBrandSize(BrandSize $brandSize): static
    {
        if ($this->sizes->removeElement($brandSize)) {
            $brandSize->removeBrand($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, BrandStyle>
     */
    public function getStyles(): Collection
    {
        return $this->styles;
    }

    public function addStyle(BrandStyle $style): static
    {
        if (!$this->styles->contains($style)) {
            $this->styles->add($style);
            $style->addBrand($this);
        }

        return $this;
    }

    public function removeStyle(BrandStyle $style): static
    {
        if ($this->styles->removeElement($style)) {
            $style->removeBrand($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, BrandAudience>
     */
    public function getAudiences(): Collection
    {
        return $this->audiences;
    }

    public function addAudience(BrandAudience $audience): static
    {
        if (!$this->audiences->contains($audience)) {
            $this->audiences->add($audience);
            $audience->addBrand($this);
        }

        return $this;
    }

    public function removeAudience(BrandAudience $audience): static
    {
        if ($this->audiences->removeElement($audience)) {
            $audience->removeBrand($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, BrandTier>
     */
    public function getTiers(): Collection
    {
        return $this->tiers;
    }

    public function addTier(BrandTier $tier): static
    {
        if (!$this->tiers->contains($tier)) {
            $this->tiers->add($tier);
            $tier->addBrand($this);
        }

        return $this;
    }

    public function removeTier(BrandTier $tier): static
    {
        if ($this->tiers->removeElement($tier)) {
            $tier->removeBrand($this);
        }

        return $this;
    }

    public function getFirstLetter(): ?string
    {
        if(empty($this->firstLetter))
        {
            $this->updateFirstLetter();
        }
        return $this->firstLetter;
    }

    public function setFirstLetter(?string $firstLetter): static
    {
        $this->firstLetter = $firstLetter;

        return $this;
    }
}
