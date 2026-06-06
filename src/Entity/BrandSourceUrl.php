<?php

namespace App\Entity;

use App\Repository\BrandSourceUrlRepository;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;

/**
 * URL-очередь discovery→fetch: discover наполняет (с дедупом по url_hash и caps по типу),
 * fetch атомарно клеймит и дренит → brand_source_document. Порядок дренажа:
 * tier ASC, relevance_score DESC (own_site/высокая уверенность раньше).
 *
 * Уникальность по (brand_id, url_hash=sha256(нормализованный url)), НЕ по url:
 * VARCHAR(1024) utf8mb4 превышает 3072-байт лимит уникального индекса InnoDB.
 */
#[ORM\Entity(repositoryClass: BrandSourceUrlRepository::class)]
#[ORM\Table(name: 'brand_source_url')]
#[ORM\UniqueConstraint(name: 'uniq_bsu_brand_hash', columns: ['brand_id', 'url_hash'])]
#[ORM\Index(name: 'idx_bsu_status_brand', columns: ['status', 'brand_id'])]
#[ORM\Index(name: 'idx_bsu_brand_tier', columns: ['brand_id', 'tier'])]
class BrandSourceUrl
{
    use Created;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_FETCHED = 'fetched';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const TYPE_OWN_SITE       = 'own_site';
    public const TYPE_OWN_PAGE       = 'own_page';   // внутренняя страница own_site, найденная краулом (sitemap/links)
    public const TYPE_PRODUCT_SAMPLE = 'product_sample'; // карточка товара (семпл) с own_site, найденная краулом — для извлечения атрибутов (keepTables при fetch)
    public const TYPE_MARKETPLACE    = 'marketplace';
    public const TYPE_CATALOG        = 'catalog';
    public const TYPE_ARTICLE_REVIEW = 'article_review';
    public const TYPE_SOCIAL         = 'social';
    public const TYPE_MENTION        = 'mention';

    public const TIER_OWN_SITE = 1;
    public const TIER_CORPUS   = 2;
    public const TIER_MENTIONS = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Brand $brand = null;

    #[ORM\Column(length: 1024)]
    private string $url = '';

    /** sha256(нормализованный url, rtrim '/') — дедуп в очереди. */
    #[ORM\Column(length: 64)]
    private string $urlHash = '';

    #[ORM\Column(length: 20)]
    private string $sourceType = self::TYPE_MENTION;

    #[ORM\Column(type: 'smallint')]
    private int $tier = self::TIER_MENTIONS;

    #[ORM\Column(type: 'float', options: ['default' => 0])]
    private float $relevanceScore = 0.0;

    #[ORM\Column(length: 12, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: 'datetime', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $discoveredAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $claimedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $fetchedAt = null;

    public function __construct()
    {
        $this->discoveredAt = new \DateTime();
    }

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

    /** Сам считает url_hash по нормализованному (lowercase host/scheme, rtrim '/') URL. */
    public function setUrl(string $url): self
    {
        $this->url = $url;
        $this->urlHash = self::normalizeHash($url);
        return $this;
    }

    public function getUrlHash(): string
    {
        return $this->urlHash;
    }

    /** sha256 нормализованного URL: схема+хост в lowercase, отрезан хвостовой '/'. */
    public static function normalizeHash(string $url): string
    {
        $normalized = trim($url);
        $parts = parse_url($normalized);
        if ($parts !== false && isset($parts['scheme'], $parts['host'])) {
            $scheme = strtolower($parts['scheme']);
            $host = strtolower($parts['host']);
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            $path = $parts['path'] ?? '';
            $query = isset($parts['query']) ? '?' . $parts['query'] : '';
            $normalized = $scheme . '://' . $host . $port . $path . $query;
        }
        $normalized = rtrim($normalized, '/');
        return hash('sha256', $normalized);
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

    public function getTier(): int
    {
        return $this->tier;
    }

    public function setTier(int $tier): self
    {
        $this->tier = $tier;
        return $this;
    }

    public function getRelevanceScore(): float
    {
        return $this->relevanceScore;
    }

    public function setRelevanceScore(float $score): self
    {
        $this->relevanceScore = $score;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): self
    {
        $this->attempts = $attempts;
        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $error): self
    {
        $this->lastError = $error;
        return $this;
    }

    public function getDiscoveredAt(): ?\DateTimeInterface
    {
        return $this->discoveredAt;
    }

    public function setDiscoveredAt(?\DateTimeInterface $at): self
    {
        $this->discoveredAt = $at;
        return $this;
    }

    public function getClaimedAt(): ?\DateTimeInterface
    {
        return $this->claimedAt;
    }

    public function setClaimedAt(?\DateTimeInterface $at): self
    {
        $this->claimedAt = $at;
        return $this;
    }

    public function getFetchedAt(): ?\DateTimeInterface
    {
        return $this->fetchedAt;
    }

    public function setFetchedAt(?\DateTimeInterface $at): self
    {
        $this->fetchedAt = $at;
        return $this;
    }
}
