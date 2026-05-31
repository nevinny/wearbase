<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Payment
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'payments')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Subscription $subscription = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $amount = null;

    #[ORM\Column(length: 3, options: ['default' => 'RUB'])]
    private string $currency = 'RUB';

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $paymentMethod = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $gatewayPaymentId = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSubscription(): ?Subscription { return $this->subscription; }
    public function setSubscription(?Subscription $subscription): static { $this->subscription = $subscription; return $this; }

    public function getAmount(): ?string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): static { $this->currency = $currency; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getPaymentMethod(): ?string { return $this->paymentMethod; }
    public function setPaymentMethod(?string $paymentMethod): static { $this->paymentMethod = $paymentMethod; return $this; }

    public function getGatewayPaymentId(): ?string { return $this->gatewayPaymentId; }
    public function setGatewayPaymentId(?string $gatewayPaymentId): static { $this->gatewayPaymentId = $gatewayPaymentId; return $this; }

    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $paidAt): static { $this->paidAt = $paidAt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function markAsPaid(?string $gatewayPaymentId = null): static
    {
        $this->status = self::STATUS_PAID;
        $this->paidAt = new \DateTimeImmutable();
        if ($gatewayPaymentId !== null) {
            $this->gatewayPaymentId = $gatewayPaymentId;
        }
        return $this;
    }

    public function markAsFailed(): static
    {
        $this->status = self::STATUS_FAILED;
        return $this;
    }
}
