<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArticleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;
use Nevinny\AdminCoreBundle\Entity\Trait\Status;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ArticleRepository::class)]
#[ORM\Table(name: 'article')]
#[ORM\Index(name: 'IDX_article_published', columns: ['status', 'locale', 'published_at'])]
#[ORM\HasLifecycleCallbacks]
class Article
{
    use Status;
    use Created;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $title;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'Слаг: только строчные латинские буквы, цифры и дефисы')]
    private string $slug;

    #[ORM\Column(length: 5, options: ['default' => 'ru'])]
    private string $locale = 'ru';

    /** Короткий анонс — выводится в списке и в meta description */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $excerpt = null;

    /** HTML-тело статьи */
    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';

    /** Дата публикации; null или будущее = не видна на сайте */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $publishedAt = null;

    /** Имя исходного .md (var/seo/blog/...) — для вывода пути Дзен-варианта в closed-loop */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceFile = null;

    /** Готовый под Дзен HTML (другая персона, var/seo/dzen/*.md) — отдаёт /rss/dzen.xml */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dzenContent = null;

    /** Имя исходного .md для dzenContent (var/seo/dzen/...), для идемпотентности привязки */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dzenSourceFile = null;

    /** Когда GSC-вотчер впервые увидел страницу в индексе */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $indexedAt = null;

    /** Когда отправлено TG «готово к Дзену» (антиповтор) */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $indexedNotifiedAt = null;

    /** Автор (E-E-A-T): байлайн + Person schema. Nullable — старые статьи без автора. */
    #[ORM\ManyToOne(targetEntity: Author::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Author $author = null;

    public function getId(): ?int { return $this->id; }

    public function getAuthor(): ?Author { return $this->author; }
    public function setAuthor(?Author $author): static { $this->author = $author; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }

    public function getLocale(): string { return $this->locale; }
    public function setLocale(string $locale): static { $this->locale = $locale; return $this; }

    public function getExcerpt(): ?string { return $this->excerpt; }
    public function setExcerpt(?string $excerpt): static { $this->excerpt = $excerpt; return $this; }

    public function getContent(): string { return $this->content; }
    public function setContent(string $content): static { $this->content = $content; return $this; }

    public function getPublishedAt(): ?\DateTime { return $this->publishedAt; }
    public function setPublishedAt(?\DateTime $publishedAt): static { $this->publishedAt = $publishedAt; return $this; }

    public function getSourceFile(): ?string { return $this->sourceFile; }
    public function setSourceFile(?string $sourceFile): static { $this->sourceFile = $sourceFile; return $this; }

    public function getDzenContent(): ?string { return $this->dzenContent; }
    public function setDzenContent(?string $dzenContent): static { $this->dzenContent = $dzenContent; return $this; }

    public function getDzenSourceFile(): ?string { return $this->dzenSourceFile; }
    public function setDzenSourceFile(?string $dzenSourceFile): static { $this->dzenSourceFile = $dzenSourceFile; return $this; }

    public function getIndexedAt(): ?\DateTime { return $this->indexedAt; }
    public function setIndexedAt(?\DateTime $indexedAt): static { $this->indexedAt = $indexedAt; return $this; }

    public function getIndexedNotifiedAt(): ?\DateTime { return $this->indexedNotifiedAt; }
    public function setIndexedNotifiedAt(?\DateTime $at): static { $this->indexedNotifiedAt = $at; return $this; }

    public function __toString(): string
    {
        return $this->title ?? 'Article #' . $this->id;
    }
}
