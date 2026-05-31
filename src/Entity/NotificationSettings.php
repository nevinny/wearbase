<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotificationSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationSettingsRepository::class)]
#[ORM\UniqueConstraint(name: 'user_event_unique', columns: ['user_id', 'event_type'])]
class NotificationSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private ?string $eventType = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $channelEmail = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $channelTelegram = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $channelInapp = true;

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getEventType(): ?string { return $this->eventType; }

    public function setEventType(string $eventType): static
    {
        $this->eventType = $eventType;
        return $this;
    }

    public function isChannelEmail(): bool { return $this->channelEmail; }
    public function setChannelEmail(bool $channelEmail): static { $this->channelEmail = $channelEmail; return $this; }

    public function isChannelTelegram(): bool { return $this->channelTelegram; }
    public function setChannelTelegram(bool $channelTelegram): static { $this->channelTelegram = $channelTelegram; return $this; }

    public function isChannelInapp(): bool { return $this->channelInapp; }
    public function setChannelInapp(bool $channelInapp): static { $this->channelInapp = $channelInapp; return $this; }

}
