<?php

namespace App\Entity;

use App\Repository\BrandStoreRepository;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;
use Nevinny\AdminCoreBundle\Entity\Trait\Status;

/**
 * Физическая точка продаж бренда (шоурум, магазин, корнер).
 */
#[ORM\Entity(repositoryClass: BrandStoreRepository::class)]
#[ORM\Table(name: 'brand_store')]
class BrandStore
{
    use Created, Status;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'stores')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Brand $brand = null;

    /** Полный адрес: улица, дом, ТЦ и пр. */
    #[ORM\Column(length: 500)]
    private string $address = '';

    /** Город (может дублировать Brand.city для магазинов в других городах) */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    /** Контактный телефон конкретной точки */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    /** Режим работы: "пн–пт 10:00–20:00" */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $workHours = null;

    /** Источник данных: 'manual' | 'enrichment' */
    #[ORM\Column(length: 20, options: ['default' => 'enrichment'])]
    private string $source = 'enrichment';

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

    public function getAddress(): string
    {
        return $this->address;
    }

    public function setAddress(string $address): static
    {
        $this->address = $address;

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

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getWorkHours(): ?string
    {
        return $this->workHours;
    }

    public function setWorkHours(?string $workHours): static
    {
        $this->workHours = $workHours;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function __toString(): string
    {
        return $this->address;
    }
}
