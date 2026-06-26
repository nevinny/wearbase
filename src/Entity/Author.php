<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuthorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;
use Nevinny\AdminCoreBundle\Entity\Trait\Status;

/**
 * Автор контента (E-E-A-T): реальный человек с экспертизой, единая сущность во всём блоге.
 * Страница /author/{slug} с Person JSON-LD (sameAs/alumniOf) — то, что строит Trust.
 */
#[ORM\Entity(repositoryClass: AuthorRepository::class)]
#[ORM\Table(name: 'author')]
class Author
{
    use Status;
    use Created;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120, unique: true)]
    private string $slug;

    #[ORM\Column(length: 120)]
    private string $name;

    /** Должность/роль — в jobTitle Person schema */
    #[ORM\Column(length: 160)]
    private string $jobTitle = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $bio = '';

    /** Полное фото (страница автора) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;

    /** Лёгкая версия для байлайна (не тянуть полное фото на каждой странице) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoSm = null;

    /** Профиль для sameAs (Instagram и т.п.) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $instagramUrl = null;

    /** alumniOf — учебное заведение/курс (профильная экспертиза) */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $schoolName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $schoolUrl = null;

    public function getId(): ?int { return $this->id; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getJobTitle(): string { return $this->jobTitle; }
    public function setJobTitle(string $jobTitle): static { $this->jobTitle = $jobTitle; return $this; }

    public function getBio(): string { return $this->bio; }
    public function setBio(string $bio): static { $this->bio = $bio; return $this; }

    public function getPhoto(): ?string { return $this->photo; }
    public function setPhoto(?string $photo): static { $this->photo = $photo; return $this; }

    public function getPhotoSm(): ?string { return $this->photoSm; }
    public function setPhotoSm(?string $photoSm): static { $this->photoSm = $photoSm; return $this; }

    public function getInstagramUrl(): ?string { return $this->instagramUrl; }
    public function setInstagramUrl(?string $url): static { $this->instagramUrl = $url; return $this; }

    public function getSchoolName(): ?string { return $this->schoolName; }
    public function setSchoolName(?string $n): static { $this->schoolName = $n; return $this; }

    public function getSchoolUrl(): ?string { return $this->schoolUrl; }
    public function setSchoolUrl(?string $u): static { $this->schoolUrl = $u; return $this; }

    public function __toString(): string { return $this->name ?? 'Author #' . $this->id; }
}
