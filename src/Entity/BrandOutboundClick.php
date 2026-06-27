<?php

namespace App\Entity;

use App\Repository\BrandOutboundClickRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only лог исходящих переходов посетителя на ссылку бренда через /go/{id}.
 * Пишется нативным SQL в OutboundClickController (горячий путь); сущность — для
 * отчётности/админки/агрегаций. Денормализованные link_type/target_host — чтобы
 * группировать без JOIN'а к brand_link (ссылка может быть удалена → FK SET NULL).
 */
#[ORM\Entity(repositoryClass: BrandOutboundClickRepository::class)]
#[ORM\Table(name: 'brand_outbound_click')]
#[ORM\Index(name: 'idx_boc_brand_created', columns: ['brand_id', 'created_at'])]
#[ORM\Index(name: 'idx_boc_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_boc_link', columns: ['brand_link_id'])]
class BrandOutboundClick
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'brand_id', nullable: false, onDelete: 'CASCADE')]
    private ?Brand $brand = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'brand_link_id', nullable: true, onDelete: 'SET NULL')]
    private ?BrandLink $brandLink = null;

    /** Нормализованный тип цели: website | instagram | vk | telegram | youtube | tiktok | marketplace | other */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $linkType = null;

    /** Хост цели (денормализован для группировки в отчётах). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $targetHost = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referer = null;

    /** sha256(User-Agent) — приблизительная уникальность без хранения PII. */
    #[ORM\Column(length: 64, options: ['fixed' => true], nullable: true)]
    private ?string $uaHash = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

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

    public function getBrandLink(): ?BrandLink
    {
        return $this->brandLink;
    }

    public function setBrandLink(?BrandLink $brandLink): static
    {
        $this->brandLink = $brandLink;

        return $this;
    }

    public function getLinkType(): ?string
    {
        return $this->linkType;
    }

    public function setLinkType(?string $linkType): static
    {
        $this->linkType = $linkType;

        return $this;
    }

    public function getTargetHost(): ?string
    {
        return $this->targetHost;
    }

    public function setTargetHost(?string $targetHost): static
    {
        $this->targetHost = $targetHost;

        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getReferer(): ?string
    {
        return $this->referer;
    }

    public function setReferer(?string $referer): static
    {
        $this->referer = $referer;

        return $this;
    }

    public function getUaHash(): ?string
    {
        return $this->uaHash;
    }

    public function setUaHash(?string $uaHash): static
    {
        $this->uaHash = $uaHash;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
