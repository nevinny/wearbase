<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ServicePaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Разовая оплата платной услуги (напр. «Размещение под ключ» 5 000₽, sales_offer.md §10).
 * Деньги идут на ПЛАТФОРМЕННЫЕ реквизиты YooKassa (тот же путь, что подписки) — не на
 * счёт бренда. Записи не удаляются физически, только статус-переход.
 */
#[ORM\Entity(repositoryClass: ServicePaymentRepository::class)]
#[ORM\Table(name: 'service_payment')]
class ServicePayment
{
    public const SERVICE_PLACEMENT = 'placement';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_CANCELED = 'canceled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private string $serviceCode = self::SERVICE_PLACEMENT;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $amount = '5000.00';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $yookassaPaymentId = null;

    /** Свободный текст — название/ссылка бренда, если оплативший указал (не привязан к Brand). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $brandHint = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getServiceCode(): string { return $this->serviceCode; }
    public function setServiceCode(string $serviceCode): static { $this->serviceCode = $serviceCode; return $this; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getYookassaPaymentId(): ?string { return $this->yookassaPaymentId; }
    public function setYookassaPaymentId(?string $yookassaPaymentId): static { $this->yookassaPaymentId = $yookassaPaymentId; return $this; }

    public function getBrandHint(): ?string { return $this->brandHint; }
    public function setBrandHint(?string $brandHint): static { $this->brandHint = $brandHint; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $paidAt): static { $this->paidAt = $paidAt; return $this; }

    public function markAsSucceeded(?string $yookassaPaymentId = null): static
    {
        $this->status = self::STATUS_SUCCEEDED;
        $this->paidAt = new \DateTimeImmutable();
        if ($yookassaPaymentId !== null) {
            $this->yookassaPaymentId = $yookassaPaymentId;
        }
        return $this;
    }

    public function markAsCanceled(): static
    {
        $this->status = self::STATUS_CANCELED;
        return $this;
    }
}
