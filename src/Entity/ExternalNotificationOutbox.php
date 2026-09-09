<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExternalNotificationOutboxRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExternalNotificationOutboxRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_external_notification_dedupe', columns: ['recipient_id', 'dedupe_key'])]
class ExternalNotificationOutbox
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\Column(length: 20)]
    private string $channel;

    #[ORM\Column(length: 50)]
    private string $notificationType;

    #[ORM\Column(length: 140)]
    private string $dedupeKey;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column]
    private \DateTimeImmutable $availableAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    /** @param array<string, mixed> $payload */
    public function __construct(User $recipient, string $channel, string $notificationType, string $dedupeKey, array $payload)
    {
        $this->recipient = $recipient;
        $this->channel = $channel;
        $this->notificationType = $notificationType;
        $this->dedupeKey = $dedupeKey;
        $this->payload = $payload;
        $this->createdAt = $this->availableAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getRecipient(): User { return $this->recipient; }
    public function getChannel(): string { return $this->channel; }
    public function getNotificationType(): string { return $this->notificationType; }
    public function getDedupeKey(): string { return $this->dedupeKey; }
    /** @return array<string, mixed> */
    public function getPayload(): array { return $this->payload; }
    public function getStatus(): string { return $this->status; }
    public function getAttempts(): int { return $this->attempts; }
    public function getAvailableAt(): \DateTimeImmutable { return $this->availableAt; }

    public function claim(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->lockedAt = $now;
        ++$this->attempts;
    }

    public function markSent(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_SENT;
        $this->sentAt = $now;
        $this->lockedAt = null;
        $this->lastError = null;
    }

    public function retry(\DateTimeImmutable $now, string $error, int $maxAttempts = 5): void
    {
        $this->lockedAt = null;
        $this->lastError = mb_substr($error, 0, 2000);
        if ($this->attempts >= $maxAttempts) {
            $this->status = self::STATUS_FAILED;
            return;
        }
        $this->status = self::STATUS_PENDING;
        $this->availableAt = $now->modify(sprintf('+%d minutes', min(60, 2 ** $this->attempts)));
    }
}
