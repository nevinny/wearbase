<?php

namespace App\Entity;

use App\Repository\BrandKeywordRepository;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;

/**
 * SEO-ключевик бренда. Собирается заранее (app:brand:keywords) из Wordstat и
 * кэшируется по brand_id; генерация читает готовое (без live-вызова Wordstat).
 * В Qdrant НЕ кладётся — это поисковый спрос, а не факты о бренде.
 *
 *  type:          origin  — фраза включает запрос (левая колонка Wordstat)
 *                 related — похожие/сопутствующие запросы (правая колонка)
 *  monthlyShows:  показов в месяц по региону (частотность Wordstat)
 */
#[ORM\Entity(repositoryClass: BrandKeywordRepository::class)]
#[ORM\Table(name: 'brand_keyword')]
#[ORM\UniqueConstraint(name: 'uniq_bkw_brand_phrase_type', columns: ['brand_id', 'keyword', 'type'])]
#[ORM\Index(name: 'idx_bkw_brand', columns: ['brand_id'])]
class BrandKeyword
{
    use Created;

    public const TYPE_ORIGIN  = 'origin';
    public const TYPE_RELATED = 'related';

    public const SOURCE_WORDSTAT = 'wordstat';
    public const SOURCE_LLM      = 'llm';

    public const REGION_RUSSIA = 225;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Brand $brand = null;

    #[ORM\Column(length: 255)]
    private string $keyword = '';

    #[ORM\Column(length: 16, options: ['default' => self::TYPE_ORIGIN])]
    private string $type = self::TYPE_ORIGIN;

    /** Показов в месяц (частотность Wordstat) по региону. */
    #[ORM\Column(nullable: true)]
    private ?int $monthlyShows = null;

    #[ORM\Column(nullable: true, options: ['default' => self::REGION_RUSSIA])]
    private ?int $region = self::REGION_RUSSIA;

    #[ORM\Column(length: 16, options: ['default' => self::SOURCE_WORDSTAT])]
    private string $source = self::SOURCE_WORDSTAT;

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

    public function getKeyword(): string
    {
        return $this->keyword;
    }

    public function setKeyword(string $keyword): self
    {
        $this->keyword = mb_substr($keyword, 0, 255);
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getMonthlyShows(): ?int
    {
        return $this->monthlyShows;
    }

    public function setMonthlyShows(?int $shows): self
    {
        $this->monthlyShows = $shows;
        return $this;
    }

    public function getRegion(): ?int
    {
        return $this->region;
    }

    public function setRegion(?int $region): self
    {
        $this->region = $region;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;
        return $this;
    }
}
