<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TariffRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TariffRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Tariff
{
    public const CODE_FREE = 'free';
    public const CODE_BASIC = 'basic';
    public const CODE_PREMIUM = 'premium';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $code = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $priceRub = '0.00';

    #[ORM\Column(options: ['default' => 30])]
    private int $trialDays = 30;

    #[ORM\Column(nullable: true)]
    private ?int $maxProducts = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxImages = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $hasAnalytics = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $hasPriority = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, Subscription>
     */
    #[ORM\OneToMany(targetEntity: Subscription::class, mappedBy: 'tariff')]
    private Collection $subscriptions;

    public function __construct()
    {
        $this->subscriptions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getCode(): ?string { return $this->code; }
    public function setCode(string $code): static { $this->code = $code; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getPriceRub(): string { return $this->priceRub; }
    public function setPriceRub(string $priceRub): static { $this->priceRub = $priceRub; return $this; }

    public function getTrialDays(): int { return $this->trialDays; }
    public function setTrialDays(int $trialDays): static { $this->trialDays = $trialDays; return $this; }

    public function getMaxProducts(): ?int { return $this->maxProducts; }
    public function setMaxProducts(?int $maxProducts): static { $this->maxProducts = $maxProducts; return $this; }

    public function getMaxImages(): ?int { return $this->maxImages; }
    public function setMaxImages(?int $maxImages): static { $this->maxImages = $maxImages; return $this; }

    public function hasAnalytics(): bool { return $this->hasAnalytics; }
    public function setHasAnalytics(bool $hasAnalytics): static { $this->hasAnalytics = $hasAnalytics; return $this; }

    public function hasPriority(): bool { return $this->hasPriority; }
    public function setHasPriority(bool $hasPriority): static { $this->hasPriority = $hasPriority; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /**
     * @return Collection<int, Subscription>
     */
    public function getSubscriptions(): Collection { return $this->subscriptions; }
}
