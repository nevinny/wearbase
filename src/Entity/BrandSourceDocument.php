<?php

namespace App\Entity;

use App\Repository\BrandSourceDocumentRepository;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;

/**
 * Скрейпленная страница-источник по бренду: сырой+очищенный текст и провенанс.
 * Хранится в MySQL (а не только в Qdrant), чтобы: переэмбеддить при смене модели
 * без повторного скрейпа; дедуплицировать по content_hash; иметь SQL-аудит
 * исключения wearbase.ru; видеть, на чём заземлено описание.
 */
#[ORM\Entity(repositoryClass: BrandSourceDocumentRepository::class)]
#[ORM\Table(name: 'brand_source_document')]
#[ORM\UniqueConstraint(name: 'uniq_bsd_brand_hash', columns: ['brand_id', 'content_hash'])]
#[ORM\Index(name: 'idx_bsd_brand', columns: ['brand_id'])]
class BrandSourceDocument
{
    use Created;

    public const TYPE_OFFICIAL = 'official_site';
    public const TYPE_SOCIAL   = 'social';
    public const TYPE_META     = 'meta';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Brand $brand = null;

    #[ORM\Column(length: 1024)]
    private string $url = '';

    #[ORM\Column(length: 20, options: ['default' => self::TYPE_OFFICIAL])]
    private string $sourceType = self::TYPE_OFFICIAL;

    #[ORM\Column(nullable: true)]
    private ?int $httpStatus = null;

    /** sha256(cleanText) — дедуп и skip-unchanged при переэмбеддинге. */
    #[ORM\Column(length: 64)]
    private string $contentHash = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rawText = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cleanText = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $charCount = 0;

    /** Ключевики (Phase 7: Wordstat либо LLM-derived). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $keywords = null;

    /** Залиты ли чанки этого документа в Qdrant. */
    #[ORM\Column(options: ['default' => false])]
    private bool $embedded = false;

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

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;
        return $this;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function setSourceType(string $type): self
    {
        $this->sourceType = $type;
        return $this;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function setHttpStatus(?int $status): self
    {
        $this->httpStatus = $status;
        return $this;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function setContentHash(string $hash): self
    {
        $this->contentHash = $hash;
        return $this;
    }

    public function getRawText(): ?string
    {
        return $this->rawText;
    }

    public function setRawText(?string $text): self
    {
        $this->rawText = $text;
        return $this;
    }

    public function getCleanText(): ?string
    {
        return $this->cleanText;
    }

    public function setCleanText(?string $text): self
    {
        $this->cleanText = $text;
        $this->charCount = $text !== null ? mb_strlen($text) : 0;
        $this->contentHash = $text !== null ? hash('sha256', $text) : '';
        return $this;
    }

    public function getCharCount(): int
    {
        return $this->charCount;
    }

    public function getKeywords(): ?string
    {
        return $this->keywords;
    }

    public function setKeywords(?string $keywords): self
    {
        $this->keywords = $keywords;
        return $this;
    }

    public function isEmbedded(): bool
    {
        return $this->embedded;
    }

    public function setEmbedded(bool $embedded): self
    {
        $this->embedded = $embedded;
        return $this;
    }
}
