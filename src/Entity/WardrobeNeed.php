<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\WardrobeNeedRepository;

#[ORM\Entity(repositoryClass: WardrobeNeedRepository::class)]
#[ORM\Table(name: 'wardrobe_need')]
#[ORM\Index(name: 'idx_wardrobe_need_family_closed', columns: ['family_id', 'closed_at'])]
class WardrobeNeed
{
    public const SEASONS = [
        'winter' => 'Зима',
        'spring' => 'Весна',
        'summer' => 'Лето',
        'autumn' => 'Осень',
        'all' => 'Всесезон',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Family $family;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $subject;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private WardrobeCategory $category;

    #[ORM\Column(length: 10)]
    private string $season;

    #[ORM\Column(length: 150)]
    private string $title;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $size = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $notes = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?PurchaseRequest $purchaseRequest = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(?Family $family, User $subject)
    {
        $this->family = $family;
        $this->subject = $subject;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getFamily(): ?Family { return $this->family; }
    public function getSubject(): User { return $this->subject; }
    public function getCategory(): WardrobeCategory { return $this->category; }
    public function getSeason(): string { return $this->season; }
    public function getTitle(): string { return $this->title; }
    public function getQuantity(): int { return $this->quantity; }
    public function getSize(): ?string { return $this->size; }
    public function getNotes(): ?string { return $this->notes; }
    public function getPurchaseRequest(): ?PurchaseRequest { return $this->purchaseRequest; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function isOpen(): bool { return $this->closedAt === null; }

    public function revise(WardrobeCategory $category, string $season, string $title, int $quantity, ?string $size, ?string $notes): void
    {
        $title = trim($title);
        $size = trim((string) $size) ?: null;
        $notes = trim((string) $notes) ?: null;
        if (!$category->isActive() || !isset(self::SEASONS[$season])
            || $title === '' || mb_strlen($title) > 150
            || $quantity < 1 || $quantity > 99
            || mb_strlen((string) $size) > 50 || mb_strlen((string) $notes) > 1000
        ) {
            throw new \InvalidArgumentException('Проверьте категорию, сезон, название, количество и размер');
        }

        $this->category = $category;
        $this->season = $season;
        $this->title = $title;
        $this->quantity = $quantity;
        $this->size = $size;
        $this->notes = $notes;
    }

    public function close(): void { $this->closedAt ??= new \DateTimeImmutable(); }
    public function reopen(): void { $this->closedAt = null; }

    public function linkPurchaseRequest(PurchaseRequest $request): void
    {
        if ($request->getItems()->count() !== 1) {
            throw new \DomainException('Для потребности укажите ссылку на один товар');
        }
        if (!$this->isOpen()) {
            throw new \DomainException('Потребность уже закрыта');
        }
        if ($request->getFamily()?->getId() !== $this->family?->getId()
            || $request->getSubject()?->getId() !== $this->subject->getId()
        ) {
            throw new \DomainException('Покупка должна быть для того же владельца и семьи');
        }
        if ($this->purchaseRequest !== null && $this->purchaseRequest->getId() !== $request->getId()) {
            throw new \DomainException('К потребности уже привязана покупка');
        }
        $this->purchaseRequest = $request;
    }
}
