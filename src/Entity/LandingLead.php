<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LandingLeadRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LandingLeadRepository::class)]
#[ORM\Table(name: 'landing_lead')]
#[ORM\HasLifecycleCallbacks]
class LandingLead
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email;

    /** Откуда пришёл: no-marketplace, for-brands, for-brands-placement, etc. */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $source = null;

    /** Название бренда — заполняется формой «Размещение под ключ» (for-brands-placement). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $brandName = null;

    /** Ссылка на сайт/соцсеть бренда (опц.) — форма «Размещение под ключ». */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTime $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getSource(): ?string { return $this->source; }
    public function setSource(?string $source): static { $this->source = $source; return $this; }

    public function getBrandName(): ?string { return $this->brandName; }
    public function setBrandName(?string $brandName): static { $this->brandName = $brandName; return $this; }

    public function getWebsite(): ?string { return $this->website; }
    public function setWebsite(?string $website): static { $this->website = $website; return $this; }

    public function getCreatedAt(): \DateTime { return $this->createdAt; }

    #[ORM\PrePersist]
    public function setCreatedAt(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTime();
        }
    }
}
