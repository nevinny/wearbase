<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArticleDistributionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;

/**
 * Копия статьи под конкретную внешнюю площадку (Дзен, vc.ru, Пикабу…) — другой тон/
 * персона, не дубль article.content (см. GenerateListicleCommand::PLATFORM_TONES —
 * тот же набор кодов площадок). Версионируемая: перегенерация площадки не затирает
 * прошлый текст, а добавляет новую строку с бОльшим version; ровно одна версия на
 * (article, platform) помечена isCurrent — её и отдают фиды/выгрузки.
 *
 * Привязка — `app:seo:attach-distribution` (var/seo/{platform}/*.md → сюда).
 */
#[ORM\Entity(repositoryClass: ArticleDistributionRepository::class)]
#[ORM\Table(name: 'article_distribution')]
#[ORM\UniqueConstraint(name: 'uniq_article_platform_version', columns: ['article_id', 'platform', 'version'])]
#[ORM\Index(name: 'idx_article_platform_current', columns: ['article_id', 'platform', 'is_current'])]
class ArticleDistribution
{
    use Created;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Article::class)]
    #[ORM\JoinColumn(name: 'article_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Article $article;

    /** Код площадки — см. GenerateListicleCommand::PLATFORM_TONES ('dzen', 'vc', 'pikabu', …). */
    #[ORM\Column(length: 32)]
    private string $platform;

    /** Порядковый номер версии в рамках (article, platform), начиная с 1. */
    #[ORM\Column]
    private int $version = 1;

    /** Ровно одна текущая версия на (article, platform) — её отдают фиды. */
    #[ORM\Column]
    private bool $isCurrent = true;

    /** Заголовок этой версии (может отличаться от article.title — другая персона/тон). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $excerpt = null;

    /** HTML-тело (парсер тот же, что у блога — ArticleMarkdownParser), без H1. */
    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';

    /** Имя исходного .md (var/seo/{platform}/...) — провенанс + идемпотентность привязки. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceFile = null;

    public function getId(): ?int { return $this->id; }

    public function getArticle(): Article { return $this->article; }
    public function setArticle(Article $article): static { $this->article = $article; return $this; }

    public function getPlatform(): string { return $this->platform; }
    public function setPlatform(string $platform): static { $this->platform = $platform; return $this; }

    public function getVersion(): int { return $this->version; }
    public function setVersion(int $version): static { $this->version = $version; return $this; }

    public function isCurrent(): bool { return $this->isCurrent; }
    public function setIsCurrent(bool $isCurrent): static { $this->isCurrent = $isCurrent; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): static { $this->title = $title; return $this; }

    public function getExcerpt(): ?string { return $this->excerpt; }
    public function setExcerpt(?string $excerpt): static { $this->excerpt = $excerpt; return $this; }

    public function getContent(): string { return $this->content; }
    public function setContent(string $content): static { $this->content = $content; return $this; }

    public function getSourceFile(): ?string { return $this->sourceFile; }
    public function setSourceFile(?string $sourceFile): static { $this->sourceFile = $sourceFile; return $this; }
}
