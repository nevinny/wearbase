<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CityRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Город.
 *
 * Используется в адресах пользователей, доставке, геотаргетинге.
 * Крупные мегаполисы имеют population для приоритизации в автодополнении.
 */
#[ORM\Entity(repositoryClass: CityRepository::class)]
#[ORM\Table(name: 'city')]
#[ORM\Index(name: 'idx_city_country', columns: ['country_id'])]
#[ORM\Index(name: 'idx_city_name_ru', columns: ['name_ru'])]
class City
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Country::class, inversedBy: 'cities')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Country $country = null;

    /** Название на русском */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $nameRu;

    /** Название на английском (транслитерация или официальный вариант) */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $nameEn = null;

    /** Регион/штат/область внутри страны */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $region = null;

    /** Широта (для карт и рассчёта расстояний) */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $latitude = null;

    /** Долгота */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $longitude = null;

    /** Население — для сортировки (Москва выше малых городов) */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $population = null;

    /** Включён в публичные списки выбора */
    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    public function __toString(): string
    {
        return $this->nameRu . ($this->region ? ", {$this->region}" : '');
    }

    // ── Getters / Setters ─────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getCountry(): ?Country { return $this->country; }
    public function setCountry(?Country $c): static { $this->country = $c; return $this; }

    public function getNameRu(): string { return $this->nameRu; }
    public function setNameRu(string $n): static { $this->nameRu = $n; return $this; }

    public function getNameEn(): ?string { return $this->nameEn; }
    public function setNameEn(?string $n): static { $this->nameEn = $n; return $this; }

    public function getRegion(): ?string { return $this->region; }
    public function setRegion(?string $r): static { $this->region = $r; return $this; }

    public function getLatitude(): ?string { return $this->latitude; }
    public function setLatitude(?string $v): static { $this->latitude = $v; return $this; }

    public function getLongitude(): ?string { return $this->longitude; }
    public function setLongitude(?string $v): static { $this->longitude = $v; return $this; }

    public function getPopulation(): ?int { return $this->population; }
    public function setPopulation(?int $v): static { $this->population = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }
}
