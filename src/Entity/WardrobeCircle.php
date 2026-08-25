<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeCircleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Кружок подруг («Кружки», docs/circles-spec.md §1): маленький круг доверия,
 * в который авторы явно расшаривают луки. Семья остаётся единственной и живёт
 * на User — кружков может быть несколько («школа» и «двор»).
 *
 * Кап членства — жёсткий на вставке (MEMBER_CAP); ≤5 активных кружков на
 * пользователя — антиспам от фермы приглашений (проверяет CircleService).
 */
#[ORM\Entity(repositoryClass: WardrobeCircleRepository::class)]
#[ORM\Table(name: 'wardrobe_circle')]
class WardrobeCircle
{
    /** Жёсткий кап участников кружка (решение PO №1). */
    public const MEMBER_CAP = 12;

    public const TITLE_MAX = 80;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $title;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $owner;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Расформирован владельцем: лента и join мертвы, строки сохраняются для аудита. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dissolvedAt = null;

    public function __construct(User $owner, string $title)
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > self::TITLE_MAX) {
            throw new \InvalidArgumentException('Название кружка обязательно и не длиннее '.self::TITLE_MAX.' символов');
        }
        $this->title = $title;
        $this->owner = $owner;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function getOwner(): User { return $this->owner; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getDissolvedAt(): ?\DateTimeImmutable { return $this->dissolvedAt; }

    public function dissolve(): void
    {
        $this->dissolvedAt ??= new \DateTimeImmutable();
    }

    public function isDissolved(): bool
    {
        return $this->dissolvedAt !== null;
    }

    /** Передача владения при выходе владельца (решение PO №5). */
    public function setOwner(User $owner): static { $this->owner = $owner; return $this; }
}
