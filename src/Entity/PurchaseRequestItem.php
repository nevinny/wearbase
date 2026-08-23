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

    #[ORM\Column(length: 12)]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne]
    private ?User $decidedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $decisionComment = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

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
}
