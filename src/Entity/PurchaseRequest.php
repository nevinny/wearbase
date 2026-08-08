<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PurchaseRequestRepository;
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

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->events = new ArrayCollection();
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
        $parts = parse_url($productUrl);
        if (strlen($productUrl) > 2048
            || $parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || preg_match('/[\x00-\x1F\x7F]/', $productUrl)
        ) {
            throw new \InvalidArgumentException('Допустима только безопасная HTTPS-ссылка');
        }

        $this->productUrl = $productUrl;
        return $this;
    }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): static { $this->comment = $comment; return $this; }
    public function getStatus(): string { return $this->status; }
    public function getDecidedBy(): ?User { return $this->decidedBy; }
    public function getDecisionComment(): ?string { return $this->decisionComment; }
    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getEvents(): Collection { return $this->events; }

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
