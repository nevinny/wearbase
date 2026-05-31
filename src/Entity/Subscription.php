<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Subscription
{
    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'subscriptions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Brand $brand = null;

    #[ORM\ManyToOne(inversedBy: 'subscriptions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tariff $tariff = null;

    #[ORM\Column(length: 20, options: ['default' => 'trial'])]
    private string $status = self::STATUS_TRIAL;

    #[ORM\Column]
    private \DateTimeImmutable $currentPeriodStart;

    #[ORM\Column]
    private \DateTimeImmutable $currentPeriodEnd;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $autoRenew = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, Payment>
     */
    #[ORM\OneToMany(targetEntity: Payment::class, mappedBy: 'subscription')]
    private Collection $payments;

    public function __construct()
    {
        $this->payments = new ArrayCollection();
        $this->currentPeriodStart = new \DateTimeImmutable();
        $this->currentPeriodEnd = new \DateTimeImmutable('+1 month');
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getBrand(): ?Brand { return $this->brand; }
    public function setBrand(?Brand $brand): static { $this->brand = $brand; return $this; }

    public function getTariff(): ?Tariff { return $this->tariff; }
    public function setTariff(?Tariff $tariff): static { $this->tariff = $tariff; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getCurrentPeriodStart(): \DateTimeImmutable { return $this->currentPeriodStart; }
    public function setCurrentPeriodStart(\DateTimeImmutable $currentPeriodStart): static { $this->currentPeriodStart = $currentPeriodStart; return $this; }

    public function getCurrentPeriodEnd(): \DateTimeImmutable { return $this->currentPeriodEnd; }
    public function setCurrentPeriodEnd(\DateTimeImmutable $currentPeriodEnd): static { $this->currentPeriodEnd = $currentPeriodEnd; return $this; }

    public function getTrialEndsAt(): ?\DateTimeImmutable { return $this->trialEndsAt; }
    public function setTrialEndsAt(?\DateTimeImmutable $trialEndsAt): static { $this->trialEndsAt = $trialEndsAt; return $this; }

    public function getCancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }
    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): static { $this->cancelledAt = $cancelledAt; return $this; }

    public function isAutoRenew(): bool { return $this->autoRenew; }
    public function setAutoRenew(bool $autoRenew): static { $this->autoRenew = $autoRenew; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /**
     * @return Collection<int, Payment>
     */
    public function getPayments(): Collection { return $this->payments; }

    public function isActive(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE && $this->status !== self::STATUS_TRIAL) {
            return false;
        }
        return $this->currentPeriodEnd === null || $this->currentPeriodEnd >= new \DateTimeImmutable();
    }

    public function isOnTrial(): bool
    {
        return $this->status === self::STATUS_TRIAL
            && $this->trialEndsAt !== null
            && $this->trialEndsAt >= new \DateTimeImmutable();
    }
}
