<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeShareReactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Реакция участника кружка на лук («огонь», docs/ratings-spec.md §5).
 *
 * Шов = пара (share_id, member_id): member_id — ЧЛЕНСТВО, а не пользователь напрямую,
 * поэтому rejoin после выхода не даёт второй голос за тот же share (uniq покрывает пару).
 * Positive-only (решение PO №1): единственная реакция — fire; колонка kind оставляет
 * задел под расширение набора позитива без миграции. В WardrobeOutfit.reaction эти
 * данные не попадают никогда — там self-feedback владельца (инвариант №1 circles-spec §4).
 *
 * Денормализованных счётчиков нет: агрегаты считаются запросом ON READ (инвариант №3).
 */
#[ORM\Entity(repositoryClass: WardrobeShareReactionRepository::class)]
#[ORM\Table(name: 'wardrobe_share_reaction')]
#[ORM\Index(columns: ['share_id'], name: 'idx_reaction_feed')]
#[ORM\UniqueConstraint(name: 'uniq_share_member_reaction', columns: ['share_id', 'member_id'])]
class WardrobeShareReaction
{
    public const KIND_FIRE = 'fire';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'share_id', nullable: false, onDelete: 'CASCADE')]
    private WardrobeOutfitShare $share;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'member_id', nullable: false, onDelete: 'CASCADE')]
    private WardrobeCircleMember $member;

    #[ORM\Column(length: 16, options: ['default' => self::KIND_FIRE])]
    private string $kind = self::KIND_FIRE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(WardrobeOutfitShare $share, WardrobeCircleMember $member)
    {
        $this->share = $share;
        $this->member = $member;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getShare(): WardrobeOutfitShare { return $this->share; }
    public function getMember(): WardrobeCircleMember { return $this->member; }
    public function getKind(): string { return $this->kind; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
