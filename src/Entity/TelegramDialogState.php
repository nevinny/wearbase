<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TelegramDialogStateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Эфемерное состояние Telegram-диалога (черновик вещи гардероба).
 * Это сессионный скретч, НЕ user-domain данные — hard-delete разрешён.
 */
#[ORM\Entity(repositoryClass: TelegramDialogStateRepository::class)]
#[ORM\Table(name: 'telegram_dialog_state')]
#[ORM\UniqueConstraint(name: 'uniq_tg_dialog_chat', columns: ['chat_id'])]
class TelegramDialogState
{
    public const STATE_COLLECTING = 'collecting';

    // Черновик старше 24ч считается протухшим (lazy-expiry, GC-крон не нужен)
    public const TTL_HOURS = 24;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $chatId;

    #[ORM\Column(length: 32)]
    private string $state = self::STATE_COLLECTING;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $draft = null;

    // BIGINT в DBAL 3 маппится в string
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $lastUpdateId = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $chatId)
    {
        $this->chatId = $chatId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getChatId(): string { return $this->chatId; }

    public function getState(): string { return $this->state; }

    public function setState(string $state): static
    {
        $this->state = $state;
        return $this;
    }

    /** @return array<string, mixed> */
    public function getDraft(): array { return $this->draft ?? []; }

    /** @param array<string, mixed>|null $draft */
    public function setDraft(?array $draft): static
    {
        $this->draft = $draft;
        return $this;
    }

    public function getLastUpdateId(): ?int
    {
        return $this->lastUpdateId === null ? null : (int) $this->lastUpdateId;
    }

    public function setLastUpdateId(?int $lastUpdateId): static
    {
        $this->lastUpdateId = $lastUpdateId === null ? null : (string) $lastUpdateId;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isStale(): bool
    {
        return $this->updatedAt < new \DateTimeImmutable(sprintf('-%d hours', self::TTL_HOURS));
    }
}
