<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PurchaseRequestItemRepository;
use App\ValueObject\MoneyAmount;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PurchaseRequestItemRepository::class)]
#[ORM\Table(name: 'purchase_request_item')]
#[ORM\Index(name: 'idx_purchase_item_request_status', columns: ['purchase_request_id', 'status'])]
class PurchaseRequestItem
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ORDERED = 'ordered';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_BOUGHT = 'bought';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_RETURNED = 'returned';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseRequest $purchaseRequest = null;

    #[ORM\Column(length: 2048)]
    private string $sourceUrl = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $estimatedPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $actualPrice = null;

    #[ORM\Column(length: 12)]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne]
    private ?User $decidedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $decisionComment = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $orderedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\OneToOne(mappedBy: 'item', targetEntity: FittingFeedback::class, cascade: ['persist'], orphanRemoval: true)]
    private ?FittingFeedback $fittingFeedback = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $sourceUrl, ?string $estimatedPrice = null)
    {
        $this->setSourceUrl($sourceUrl);
        $this->setEstimatedPrice($estimatedPrice);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPurchaseRequest(): ?PurchaseRequest { return $this->purchaseRequest; }
    public function setPurchaseRequest(PurchaseRequest $purchaseRequest): static { $this->purchaseRequest = $purchaseRequest; return $this; }
    public function getSourceUrl(): string { return $this->sourceUrl; }
    public function setSourceUrl(string $sourceUrl): static
    {
        PurchaseRequest::assertSafeProductUrl($sourceUrl);
        $this->sourceUrl = $sourceUrl;
        return $this;
    }
    public function getEstimatedPrice(): ?string { return $this->estimatedPrice; }
    public function setEstimatedPrice(?string $estimatedPrice): static
    {
        $this->estimatedPrice = $estimatedPrice === null ? null : MoneyAmount::normalize($estimatedPrice);
        return $this;
    }
    public function getStatus(): string { return $this->status; }
    public function getActualPrice(): ?string { return $this->actualPrice; }
    public function getOrderedAt(): ?\DateTimeImmutable { return $this->orderedAt; }
    public function getDeliveredAt(): ?\DateTimeImmutable { return $this->deliveredAt; }
    public function getFittingFeedback(): ?FittingFeedback { return $this->fittingFeedback; }
    public function getDecidedBy(): ?User { return $this->decidedBy; }
    public function getDecisionComment(): ?string { return $this->decisionComment; }
    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function decide(string $status, User $actor, ?string $comment = null): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \DomainException('Решение по позиции уже принято');
        }
        if (!in_array($status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            throw new \InvalidArgumentException('Недопустимое решение');
        }
        $comment = $comment !== null && trim($comment) !== '' ? trim($comment) : null;
        if ($status === self::STATUS_REJECTED && $comment === null) {
            throw new \DomainException('Укажите причину отказа');
        }
        if ($comment !== null && mb_strlen($comment) > 2000) {
            throw new \InvalidArgumentException('Комментарий не должен превышать 2000 символов');
        }

        $this->status = $status;
        $this->decidedBy = $actor;
        $this->decisionComment = $comment;
        $this->decidedAt = new \DateTimeImmutable();
    }

    public function markOrdered(?string $actualPrice = null): void
    {
        $this->assertStatus(self::STATUS_APPROVED);
        $this->actualPrice = $actualPrice === null ? $this->estimatedPrice : MoneyAmount::normalize($actualPrice);
        $this->status = self::STATUS_ORDERED;
        $this->orderedAt = new \DateTimeImmutable();
    }

    public function markDelivered(): void
    {
        $this->assertStatus(self::STATUS_ORDERED);
        $this->status = self::STATUS_DELIVERED;
        $this->deliveredAt = new \DateTimeImmutable();
    }

    public function recordFitting(FittingFeedback $feedback): void
    {
        if (!in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_BOUGHT], true)) {
            throw new \DomainException('Примерка доступна только после получения вещи');
        }
        $feedback->setItem($this);
        $this->fittingFeedback = $feedback;
        $this->status = match ($feedback->getOutcome()) {
            FittingFeedback::OUTCOME_BOUGHT => self::STATUS_BOUGHT,
            FittingFeedback::OUTCOME_REFUSED, FittingFeedback::OUTCOME_DIFFERENT_SIZE => self::STATUS_REFUSED,
            default => self::STATUS_DELIVERED,
        };
    }

    public function markReturned(): void
    {
        $this->assertStatus(self::STATUS_BOUGHT);
        $this->status = self::STATUS_RETURNED;
    }

    private function assertStatus(string $expected): void
    {
        if ($this->status !== $expected) {
            throw new \DomainException('Недопустимый переход статуса позиции');
        }
    }
}
