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

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseRequest $purchaseRequest = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $actor = null;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $actor, string $type)
    {
        $this->actor = $actor;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPurchaseRequest(): ?PurchaseRequest { return $this->purchaseRequest; }
    public function setPurchaseRequest(PurchaseRequest $purchaseRequest): static { $this->purchaseRequest = $purchaseRequest; return $this; }
    public function getActor(): ?User { return $this->actor; }
    public function getType(): string { return $this->type; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
