<?php

namespace App\Entity;

use App\Repository\CompetitorArticleRepository;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;

/**
 * Кэш скрейпнутого контента конкурента по URL (docs/seo_competitor_content.md):
 * одна и та же статья может встретиться в выдаче под несколько SEO-фраз — не
 * перескрейпим (см. app:seo:competitor-scan, 30-дневный кэш по fetchedAt).
 *
 * pageType: article|marketplace|social|official_brand_site|other — только article
 * идёт в gap-анализ (CompetitorPageClassifier).
 */
#[ORM\Entity(repositoryClass: CompetitorArticleRepository::class)]
#[ORM\Table(name: 'competitor_article')]
#[ORM\UniqueConstraint(name: 'uniq_competitor_article_url', columns: ['url'])]
class CompetitorArticle
{
    use Created;

    public const TYPE_ARTICLE         = 'article';
    public const TYPE_MARKETPLACE     = 'marketplace';
    public const TYPE_SOCIAL          = 'social';
    public const TYPE_OFFICIAL_BRAND  = 'official_brand_site';
    public const TYPE_OTHER           = 'other';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** utf8mb4 unique-индекс ограничен 3072 байтами (768 симв. × 4 байта) — длиннее URL пропускаем. */
    #[ORM\Column(length: 768)]
    private string $url = '';

    #[ORM\Column(length: 255)]
    private string $domain = '';

    #[ORM\Column(length: 20, options: ['default' => self::TYPE_OTHER])]
    private string $pageType = self::TYPE_OTHER;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $content = null;

    #[ORM\Column(nullable: true)]
    private ?int $wordCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $httpStatus = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $fetchedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = mb_substr($url, 0, 768);

        return $this;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): self
    {
        $this->domain = mb_substr($domain, 0, 255);

        return $this;
    }

    public function getPageType(): string
    {
        return $this->pageType;
    }

    public function setPageType(string $pageType): self
    {
        $this->pageType = $pageType;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title !== null ? mb_substr($title, 0, 255) : null;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function getWordCount(): ?int
    {
        return $this->wordCount;
    }

    public function setWordCount(?int $wordCount): self
    {
        $this->wordCount = $wordCount;

        return $this;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function setHttpStatus(?int $httpStatus): self
    {
        $this->httpStatus = $httpStatus;

        return $this;
    }

    public function getFetchedAt(): ?\DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function setFetchedAt(?\DateTimeImmutable $fetchedAt): self
    {
        $this->fetchedAt = $fetchedAt;

        return $this;
    }

    /** 30-дневный кэш (аналогия WebScraperService, см. CLAUDE.md) — не перескрейпим свежее. */
    public function isFresh(\DateTimeImmutable $now, int $days = 30): bool
    {
        return $this->fetchedAt !== null && $this->fetchedAt > $now->modify("-{$days} days");
    }
}
