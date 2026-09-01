<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PurchaseRequestRepository;
use App\ValueObject\ExternalProductUrl;
use App\ValueObject\MoneyAmount;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PurchaseRequestRepository::class)]
#[ORM\Table(name: 'purchase_request')]
#[ORM\Index(name: 'idx_purchase_request_family_status', columns: ['family_id', 'status'])]
class PurchaseRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PARTIAL = 'partial';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Family $family = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $subject = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $createdBy = null;

    #[ORM\Column(length: 2048)]
    private string $productUrl = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

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

    /** @var Collection<int, PurchaseRequestEvent> */
    #[ORM\OneToMany(mappedBy: 'purchaseRequest', targetEntity: PurchaseRequestEvent::class, cascade: ['persist'])]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $events;

    /** @var Collection<int, PurchaseRequestItem> */
    #[ORM\OneToMany(mappedBy: 'purchaseRequest', targetEntity: PurchaseRequestItem::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $items;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->events = new ArrayCollection();
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getFamily(): ?Family { return $this->family; }
    public function setFamily(Family $family): static { $this->family = $family; return $this; }
    public function getSubject(): ?User { return $this->subject; }
    public function setSubject(User $subject): static { $this->subject = $subject; return $this; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function getRequestedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(User $createdBy): static { $this->createdBy = $createdBy; return $this; }
    public function getProductUrl(): string { return $this->productUrl; }
    public function setProductUrl(string $productUrl): static
    {
        $this->productUrl = ExternalProductUrl::fromString($productUrl)->toString();
        return $this;
    }

    public static function assertSafeProductUrl(string $productUrl): void
    {
        ExternalProductUrl::fromString($productUrl);
    }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): static
    {
        if ($comment !== null && mb_strlen($comment) > 2000) {
            throw new \InvalidArgumentException('Комментарий не должен превышать 2000 символов');
        }
        $this->comment = $comment;
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
    public function getEvents(): Collection { return $this->events; }
    /** @return Collection<int, PurchaseRequestItem> */
    public function getItems(): Collection { return $this->items; }

    public function addItem(PurchaseRequestItem $item): void
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setPurchaseRequest($this);
        }
    }

    public function refreshDecisionFromItems(): void
    {
        if ($this->items->isEmpty()) {
            return;
        }
        $statuses = array_unique($this->items->map(
            static fn (PurchaseRequestItem $item): string => $item->getStatus(),
        )->toArray());
        if (in_array(PurchaseRequestItem::STATUS_PENDING, $statuses, true)) {
            $this->status = self::STATUS_PENDING;
            return;
        }
        $this->status = count($statuses) === 1
            ? ($statuses[0] === PurchaseRequestItem::STATUS_APPROVED ? self::STATUS_APPROVED : self::STATUS_REJECTED)
            : self::STATUS_PARTIAL;
        $last = $this->items->last();
        $this->decidedBy = $last->getDecidedBy();
        $this->decisionComment = $last->getDecisionComment();
        $this->decidedAt = $last->getDecidedAt();
    }

    public function decide(string $status, User $actor, ?string $comment = null): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \DomainException('Решение по запросу уже принято');
        }
        if (!in_array($status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            throw new \InvalidArgumentException('Недопустимое решение');
        }
        if ($status === self::STATUS_REJECTED && trim((string) $comment) === '') {
            throw new \DomainException('Укажите причину отказа');
        }
        if ($comment !== null && mb_strlen($comment) > 2000) {
            throw new \InvalidArgumentException('Комментарий не должен превышать 2000 символов');
        }

        $this->status = $status;
        $this->decidedBy = $actor;
        $this->decisionComment = $comment !== null && trim($comment) !== '' ? trim($comment) : null;
        $this->decidedAt = new \DateTimeImmutable();
    }

    public function addEvent(PurchaseRequestEvent $event): void
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
            $event->setPurchaseRequest($this);
        }
    }
}
