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

    public function getId(): ?int { return $this->id; }

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

    public function __toString(): string
    {
        return $this->title ?? 'Article #' . $this->id;
    }
}
