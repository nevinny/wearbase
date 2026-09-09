<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeItemLifecycleEventRepository;
use App\ValueObject\MoneyAmount;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WardrobeItemLifecycleEventRepository::class)]
#[ORM\Table(name: 'wardrobe_item_lifecycle_event')]
#[ORM\Index(name: 'idx_wardrobe_lifecycle_item_status', columns: ['item_id', 'status'])]
class WardrobeItemLifecycleEvent
{
    public const TYPE_DRY_CLEANING = 'dry_cleaning';
    public const TYPE_CLEANING = 'cleaning';
    public const TYPE_REPAIR_HEM = 'repair_hem';
    public const TYPE_REPAIR_ZIPPER = 'repair_zipper';
    public const TYPE_REPAIR_SOLE = 'repair_sole';
    public const TYPE_REPAIR_OTHER = 'repair_other';
    public const TYPE_TRANSFER_EXTERNAL = 'transfer_external';
    public const CARE_TYPES = [self::TYPE_DRY_CLEANING, self::TYPE_CLEANING, self::TYPE_REPAIR_HEM, self::TYPE_REPAIR_ZIPPER, self::TYPE_REPAIR_SOLE, self::TYPE_REPAIR_OTHER];
    public const TYPES = [...self::CARE_TYPES, self::TYPE_TRANSFER_EXTERNAL];

    public const STATUS_OPEN = 'open';
    public const STATUS_COMPLETED = 'completed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WardrobeItem $item;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $profileSubject;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $actor;

    #[ORM\Column(length: 24)]
    private string $type;

    #[ORM\Column(length: 12)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $provider;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $cost;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(WardrobeItem $item, User $profileSubject, User $actor, string $type, ?string $provider = null, ?string $cost = null, ?string $note = null)
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Недопустимый тип события вещи');
        }
        $provider = trim((string) $provider);
        $note = trim((string) $note);
        if (mb_strlen($provider) > 255 || mb_strlen($note) > 2000) {
            throw new \InvalidArgumentException('Слишком длинное описание');
        }
        $this->item = $item;
        $this->profileSubject = $profileSubject;
        $this->actor = $actor;
        $this->type = $type;
        $this->provider = $provider !== '' ? $provider : null;
        $this->cost = $cost !== null && trim($cost) !== '' ? MoneyAmount::normalize($cost) : null;
        $this->note = $note !== '' ? $note : null;
        $this->createdAt = new \DateTimeImmutable();
        if ($type === self::TYPE_TRANSFER_EXTERNAL) {
            $this->complete();
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getItem(): WardrobeItem { return $this->item; }
    public function getProfileSubject(): User { return $this->profileSubject; }
    public function getActor(): User { return $this->actor; }
    public function getType(): string { return $this->type; }
    public function getStatus(): string { return $this->status; }
    public function getProvider(): ?string { return $this->provider; }
    public function getCost(): ?string { return $this->cost; }
    public function getNote(): ?string { return $this->note; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }

    public function complete(): void
    {
        if ($this->status === self::STATUS_COMPLETED) {
            return;
        }
        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = new \DateTimeImmutable();
    }
}
