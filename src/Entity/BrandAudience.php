<?php

namespace App\Entity;

use App\Repository\BrandAudienceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;
use Nevinny\AdminCoreBundle\Entity\Trait\DefaultFields;
use Nevinny\AdminCoreBundle\Entity\Trait\Owner;
use Nevinny\AdminCoreBundle\Entity\Trait\Status;

#[ORM\Entity(repositoryClass: BrandAudienceRepository::class)]
class BrandAudience
{
    use DefaultFields, Created, Owner, Status;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Человеко-читаемая формулировка H1/title публичной страницы /audience/{slug}
     * (docs/geo_city_demand_2026_09.md §11) — дословные формулировки из Wordstat
     * («Российские бренды женской одежды»), а не «Бренды одежды для аудитории Женщины».
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $h1 = null;

    /**
     * @var Collection<int, Brand>
     */
    #[ORM\ManyToMany(targetEntity: Brand::class, inversedBy: 'audiences')]
    private Collection $brands;

    public function __construct()
    {
        $this->brands = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getH1(): ?string
    {
        return $this->h1;
    }

    public function setH1(?string $h1): static
    {
        $this->h1 = $h1;

        return $this;
    }

    /**
     * @return Collection<int, Brand>
     */
    public function getBrands(): Collection
    {
        return $this->brands;
    }

    public function addBrand(Brand $brand): static
    {
        if (!$this->brands->contains($brand)) {
            $this->brands->add($brand);
        }

        return $this;
    }

    public function removeBrand(Brand $brand): static
    {
        $this->brands->removeElement($brand);

        return $this;
    }
}
