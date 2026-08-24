<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PurchaseRequestEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PurchaseRequestEventRepository::class)]
#[ORM\Table(name: 'purchase_request_event')]
class PurchaseRequestEvent
{
    public const TYPE_CREATED = 'created';
    public const TYPE_APPROVED = 'approved';
    public const TYPE_REJECTED = 'rejected';
    public const TYPE_APPROVED_OVER_BUDGET = 'approved_over_budget';
    public const TYPE_APPROVED_NO_PRICE = 'approved_no_price';
    public const TYPE_ORDERED = 'ordered';
    public const TYPE_ORDERED_OVER_BUDGET = 'ordered_over_budget';
    public const TYPE_DELIVERED = 'delivered';
    public const TYPE_FITTING = 'fitting';
    public const TYPE_RETURNED = 'returned';
    public const TYPE_ADDED_TO_WARDROBE = 'added_to_wardrobe';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseRequest $purchaseRequest = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PurchaseRequestItem $item = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $actor = null;

    #[ORM\Column(length: 20)]
    private string $type;

    /** @var array<string, string|bool>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, string|bool>|null $metadata
     */
    public function __construct(User $actor, string $type, ?array $metadata = null)
    {
        $this->actor = $actor;
        $this->type = $type;
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPurchaseRequest(): ?PurchaseRequest { return $this->purchaseRequest; }
    public function setPurchaseRequest(PurchaseRequest $purchaseRequest): static { $this->purchaseRequest = $purchaseRequest; return $this; }
    public function getActor(): ?User { return $this->actor; }
    public function getItem(): ?PurchaseRequestItem { return $this->item; }
    public function setItem(?PurchaseRequestItem $item): static { $this->item = $item; return $this; }
    public function getType(): string { return $this->type; }
    /** @return array<string, string|bool>|null */
    public function getMetadata(): ?array { return $this->metadata; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
