<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: '`order`')]
#[ORM\HasLifecycleCallbacks]
class Order
{
    // Статусы заказа
    public const STATUS_NEW = 'new';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_REFUNDED = 'refunded';

    // Статусы оплаты
    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_REFUNDED = 'refunded';
    public const PAYMENT_FAILED = 'failed';

    // Методы оплаты
    public const PAYMENT_METHOD_CARD = 'card_online';
    public const PAYMENT_METHOD_SBP = 'sbp';
    public const PAYMENT_METHOD_RECEIPT = 'upon_receipt';

    // Методы доставки
    public const DELIVERY_COURIER = 'courier';
    public const DELIVERY_PICKUP = 'pickup';
    public const DELIVERY_CDEK = 'cdek';
    public const DELIVERY_BOXBERRY = 'boxberry';
    public const DELIVERY_POCHTA = 'pochta';

    public static function getStatusLabels(): array
    {
        return [
            self::STATUS_NEW => 'Новый',
            self::STATUS_CONFIRMED => 'Подтверждён',
            self::STATUS_PROCESSING => 'В обработке',
            self::STATUS_SHIPPED => 'Отправлен',
            self::STATUS_DELIVERED => 'Доставлен',
            self::STATUS_COMPLETED => 'Завершён',
            self::STATUS_CANCELLED => 'Отменён',
            self::STATUS_RETURNED => 'Возврат',
            self::STATUS_REFUNDED => 'Возвращены средства',
        ];
    }

    // Переходы разрешённые для бренда
    public static function getBrandAllowedTransitions(): array
    {
        return [
            self::STATUS_NEW => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
            self::STATUS_CONFIRMED => [self::STATUS_PROCESSING, self::STATUS_CANCELLED],
            self::STATUS_PROCESSING => [self::STATUS_SHIPPED],
        ];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, unique: true)]
    private ?string $orderNumber = null;

    #[ORM\ManyToOne(inversedBy: 'orders')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $customer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Brand $brand = null;

    #[ORM\Column(length: 20, options: ['default' => 'new'])]
    private string $status = self::STATUS_NEW;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $gatewayPaymentId = null;

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $paymentStatus = self::PAYMENT_PENDING;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $paymentMethod = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $deliveryMethod = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $trackingNumber = null;

    // JSON: {fullName, phone, city, street, building, apartment, zip}
    #[ORM\Column(type: Types::JSON)]
    private array $shippingAddress = [];

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $subtotal = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $shippingCost = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $discountAmount = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $totalAmount = '0.00';

    #[ORM\Column(length: 3, options: ['default' => 'RUB'])]
    private string $currency = 'RUB';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $customerNote = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNote = null;

    /**
     * @var Collection<int, OrderItem>
     */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    private Collection $orderItems;

    /**
     * @var Collection<int, OrderStatusHistory>
     */
    #[ORM\OneToMany(targetEntity: OrderStatusHistory::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    private Collection $statusHistory;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $this->orderItems = new ArrayCollection();
        $this->statusHistory = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getOrderNumber(): ?string { return $this->orderNumber; }

    public function setOrderNumber(string $orderNumber): static
    {
        $this->orderNumber = $orderNumber;
        return $this;
    }

    public function getCustomer(): ?User { return $this->customer; }

    public function setCustomer(?User $customer): static
    {
        $this->customer = $customer;
        return $this;
    }

    public function getBrand(): ?Brand { return $this->brand; }

    public function setBrand(?Brand $brand): static
    {
        $this->brand = $brand;
        return $this;
    }

    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        if ($status === self::STATUS_COMPLETED) {
            $this->completedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function getStatusLabel(): string
    {
        return self::getStatusLabels()[$this->status] ?? $this->status;
    }

    public function getGatewayPaymentId(): ?string { return $this->gatewayPaymentId; }

    public function setGatewayPaymentId(?string $gatewayPaymentId): static
    {
        $this->gatewayPaymentId = $gatewayPaymentId;
        return $this;
    }

    public function getPaymentStatus(): string { return $this->paymentStatus; }

    public function setPaymentStatus(string $paymentStatus): static
    {
        $this->paymentStatus = $paymentStatus;
        return $this;
    }

    public function isPaid(): bool { return $this->paymentStatus === self::PAYMENT_PAID; }

    public function getPaymentMethod(): ?string { return $this->paymentMethod; }

    public function setPaymentMethod(?string $paymentMethod): static
    {
        $this->paymentMethod = $paymentMethod;
        return $this;
    }

    public function getDeliveryMethod(): ?string { return $this->deliveryMethod; }

    public function setDeliveryMethod(?string $deliveryMethod): static
    {
        $this->deliveryMethod = $deliveryMethod;
        return $this;
    }

    public function getTrackingNumber(): ?string { return $this->trackingNumber; }

    public function setTrackingNumber(?string $trackingNumber): static
    {
        $this->trackingNumber = $trackingNumber;
        return $this;
    }

    public function getShippingAddress(): array { return $this->shippingAddress; }

    public function setShippingAddress(array $shippingAddress): static
    {
        $this->shippingAddress = $shippingAddress;
        return $this;
    }

    public function getSubtotal(): string { return $this->subtotal; }
    public function setSubtotal(string $subtotal): static { $this->subtotal = $subtotal; return $this; }

    public function getShippingCost(): string { return $this->shippingCost; }
    public function setShippingCost(string $shippingCost): static { $this->shippingCost = $shippingCost; return $this; }

    public function getDiscountAmount(): string { return $this->discountAmount; }
    public function setDiscountAmount(string $discountAmount): static { $this->discountAmount = $discountAmount; return $this; }

    public function getTotalAmount(): string { return $this->totalAmount; }
    public function setTotalAmount(string $totalAmount): static { $this->totalAmount = $totalAmount; return $this; }

    public function getCurrency(): string { return $this->currency; }

    public function getCustomerNote(): ?string { return $this->customerNote; }
    public function setCustomerNote(?string $customerNote): static { $this->customerNote = $customerNote; return $this; }

    public function getAdminNote(): ?string { return $this->adminNote; }
    public function setAdminNote(?string $adminNote): static { $this->adminNote = $adminNote; return $this; }

    public function getOrderItems(): Collection { return $this->orderItems; }

    public function addOrderItem(OrderItem $item): static
    {
        if (!$this->orderItems->contains($item)) {
            $this->orderItems->add($item);
            $item->setOrder($this);
        }
        return $this;
    }

    public function getStatusHistory(): Collection { return $this->statusHistory; }

    public function addStatusHistory(OrderStatusHistory $history): static
    {
        $this->statusHistory->add($history);
        $history->setOrder($this);
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }

    public function canBeCancelledByCustomer(): bool
    {
        return in_array($this->status, [self::STATUS_NEW, self::STATUS_CONFIRMED], true);
    }
}
