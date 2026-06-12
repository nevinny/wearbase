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

    /** Откуда пришёл: no-marketplace, for-brands, etc. */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $source = null;

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

    public function getCreatedAt(): \DateTime { return $this->createdAt; }

    #[ORM\PrePersist]
    public function setCreatedAt(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTime();
        }
    }
}
