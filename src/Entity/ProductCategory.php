<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductCategoryRepository::class)]
class ProductCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    private ?self $parent = null;

    /**
     * @var Collection<int, self>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    private Collection $children;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $ord = 0;

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = 'active';

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getSlug(): ?string { return $this->slug; }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getTitle(): ?string { return $this->title; }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getParent(): ?self { return $this->parent; }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;
        return $this;
    }

    public function getChildren(): Collection { return $this->children; }

    public function getIcon(): ?string { return $this->icon; }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;
        return $this;
    }

    public function getOrd(): int { return $this->ord; }

    public function setOrd(int $ord): static
    {
        $this->ord = $ord;
        return $this;
    }

    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function __toString(): string { return $this->title ?? ''; }
}
