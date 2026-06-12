<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AddressRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AddressRepository::class)]
class Address
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'addresses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    // Метка: "Домашний", "Рабочий", "Дача"
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(length: 255)]
    private ?string $fullName = null;

    #[ORM\Column(length: 20)]
    private ?string $phone = null;

    #[ORM\Column(length: 2, options: ['default' => 'RU'])]
    private string $country = 'RU';

    #[ORM\Column(length: 100)]
    private ?string $city = null;

    #[ORM\Column(length: 255)]
    private ?string $street = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $building = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $apartment = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $zip = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isDefault = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

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

    public function getLabel(): ?string { return $this->label; }

    public function setLabel(?string $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function getFullName(): ?string { return $this->fullName; }

    public function setFullName(string $fullName): static
    {
        $this->fullName = $fullName;
        return $this;
    }

    public function getPhone(): ?string { return $this->phone; }

    public function setPhone(string $phone): static
    {
        $this->phone = $phone;
        return $this;
    }

    public function getCountry(): string { return $this->country; }

    public function setCountry(string $country): static
    {
        $this->country = $country;
        return $this;
    }

    public function getCity(): ?string { return $this->city; }

    public function setCity(string $city): static
    {
        $this->city = $city;
        return $this;
    }

    public function getStreet(): ?string { return $this->street; }

    public function setStreet(string $street): static
    {
        $this->street = $street;
        return $this;
    }

    public function getBuilding(): ?string { return $this->building; }

    public function setBuilding(?string $building): static
    {
        $this->building = $building;
        return $this;
    }

    public function getApartment(): ?string { return $this->apartment; }

    public function setApartment(?string $apartment): static
    {
        $this->apartment = $apartment;
        return $this;
    }

    public function getZip(): ?string { return $this->zip; }

    public function setZip(?string $zip): static
    {
        $this->zip = $zip;
        return $this;
    }

    public function isDefault(): bool { return $this->isDefault; }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getFullAddress(): string
    {
        $parts = array_filter([
            $this->zip,
            $this->city,
            $this->street,
            $this->building ? 'д. ' . $this->building : null,
            $this->apartment ? 'кв. ' . $this->apartment : null,
        ]);
        return implode(', ', $parts);
    }
}
