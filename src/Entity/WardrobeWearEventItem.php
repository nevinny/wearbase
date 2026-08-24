<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wardrobe_wear_event_item')]
#[ORM\UniqueConstraint(name: 'uniq_wear_event_item', columns: ['event_id', 'item_id'])]
class WardrobeWearEventItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WardrobeWearEvent $event;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WardrobeItem $item;
    #[ORM\Column(length: 12)]
    private string $selectionSource;
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $confidence;
    #[ORM\Column]
    private bool $confirmed = false;

    public function __construct(WardrobeWearEvent $event, WardrobeItem $item, string $selectionSource, ?string $confidence)
    {
        if (!in_array($selectionSource, ['vision', 'manual', 'ai_outfit'], true)) {
            throw new \InvalidArgumentException('Неизвестный источник выбора вещи');
        }
        $this->event = $event;
        $this->item = $item;
        $this->selectionSource = $selectionSource;
        $this->confidence = in_array($confidence, ['high', 'med', 'low'], true) ? $confidence : null;
    }

    public function getId(): ?int { return $this->id; }
    public function getEvent(): WardrobeWearEvent { return $this->event; }
    public function getItem(): WardrobeItem { return $this->item; }
    public function getSelectionSource(): string { return $this->selectionSource; }
    public function getConfidence(): ?string { return $this->confidence; }
    public function isConfirmed(): bool { return $this->confirmed; }
    public function confirm(): void { $this->confirmed = true; }
}
