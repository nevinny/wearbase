<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FamilyMembershipEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FamilyMembershipEventRepository::class)]
#[ORM\Table(name: 'family_membership_event')]
class FamilyMembershipEvent
{
    public const TYPE_ADULTHOOD = 'adulthood';
    public const TYPE_OWNER_TRANSFERRED = 'owner_transferred';
    public const TYPE_MEMBER_REMOVED = 'member_removed';
    public const TYPE_MEMBER_LEFT = 'member_left';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Family $family;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $actor;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $subject;
    #[ORM\Column(length: 24)]
    private string $type;
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Family $family, User $actor, User $subject, string $type)
    {
        if (!in_array($type, [self::TYPE_ADULTHOOD, self::TYPE_OWNER_TRANSFERRED, self::TYPE_MEMBER_REMOVED, self::TYPE_MEMBER_LEFT], true)) {
            throw new \InvalidArgumentException('Недопустимое семейное событие');
        }
        $this->family = $family;
        $this->actor = $actor;
        $this->subject = $subject;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
    }
    public function getId(): ?int { return $this->id; }
    public function getFamily(): Family { return $this->family; }
    public function getActor(): User { return $this->actor; }
    public function getSubject(): User { return $this->subject; }
    public function getType(): string { return $this->type; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
