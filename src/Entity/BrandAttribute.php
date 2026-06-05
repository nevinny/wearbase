<?php

namespace App\Entity;

use App\Repository\BrandAttributeRepository;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;

/**
 * Структурированный атрибут бренда из LLM-extract по краулу (стиль/категория/
 * gender/материал/ценовой сегмент/гео/размерный ряд). EAV — не ложится в
 * курируемые справочники, плюс не загрязняет их LLM-шумом. provenance +
 * краудсорс-валидация (target_type='brand_attribute' в BrandDatapoint).
 */
#[ORM\Entity(repositoryClass: BrandAttributeRepository::class)]
#[ORM\Table(name: 'brand_attribute')]
#[ORM\UniqueConstraint(name: 'uniq_battr', columns: ['brand_id', 'name', 'value'])]
#[ORM\Index(name: 'idx_battr_brand', columns: ['brand_id'])]
class BrandAttribute
{
    use Created;

    public const NAME_STYLE         = 'style';
    public const NAME_CATEGORY      = 'category';
    public const NAME_GENDER        = 'gender';
    public const NAME_MATERIAL      = 'material';
    public const NAME_PRICE_SEGMENT = 'price_segment';
    public const NAME_GEO           = 'geo';
    public const NAME_SIZE          = 'size';

    public const PROV_ENRICHMENT      = 'enrichment';
    public const PROV_OWNER           = 'owner';
    public const PROV_CROWD_CONFIRMED = 'crowd_confirmed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Brand $brand = null;

    #[ORM\Column(length: 40)]
    private string $name = '';

    #[ORM\Column(length: 255)]
    private string $value = '';

    #[ORM\Column(length: 16, options: ['default' => self::PROV_ENRICHMENT])]
    private string $provenance = self::PROV_ENRICHMENT;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBrand(): ?Brand
    {
        return $this->brand;
    }

    public function setBrand(?Brand $brand): self
    {
        $this->brand = $brand;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;
        return $this;
    }

    public function getProvenance(): string
    {
        return $this->provenance;
    }

    public function setProvenance(string $provenance): self
    {
        $this->provenance = $provenance;
        return $this;
    }
}
