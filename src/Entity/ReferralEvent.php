<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReferralEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Атрибуция «привёл друга» (спец §7, решение PO №6): одна запись после успешной
 * регистрации гостя, пришедшего по ссылке лука. Награды/лимиты — вне MVP,
 * отдельный спек «Реферальная программа». Никаких UTM: источник — сама share-строка.
 */
#[ORM\Entity(repositoryClass: ReferralEventRepository::class)]
#[ORM\Table(name: 'referral_event')]
#[ORM\Index(columns: ['inviter_id'], name: 'idx_ref_inviter')]
#[ORM\Index(columns: ['invitee_id'], name: 'idx_ref_invitee')]
#[ORM\UniqueConstraint(name: 'uniq_referral_once', columns: ['invitee_id', 'source', 'share_id'])]
class ReferralEvent
{
    public const SOURCE_LOOK_SHARE = 'look_share';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Автор лука, чьей ссылкой пригласили. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $inviter;

    /** Зарегистрировавшийся гость. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $invitee;

    #[ORM\Column(length: 32)]
    private string $source = self::SOURCE_LOOK_SHARE;

    /** Без FK: share может быть удалён каскадом лука, событие атрибуции должно пережить его. */
    #[ORM\Column(nullable: true)]
    private ?int $shareId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $inviter, User $invitee, string $source, ?int $shareId = null)
    {
        $this->inviter = $inviter;
        $this->invitee = $invitee;
        $this->source = $source;
        $this->shareId = $shareId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getInviter(): User { return $this->inviter; }
    public function getInvitee(): User { return $this->invitee; }
    public function getSource(): string { return $this->source; }
    public function getShareId(): ?int { return $this->shareId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
