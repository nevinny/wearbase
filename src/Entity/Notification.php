<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_notification_recipient_dedupe', columns: ['recipient_id', 'dedupe_key'])]
class Notification
{
    // Типы событий
    public const TYPE_ORDER_NEW = 'order_new';
    public const TYPE_ORDER_STATUS = 'order_status_changed';
    public const TYPE_ORDER_SHIPPED = 'order_shipped';
    public const TYPE_ORDER_DELIVERED = 'order_delivered';
    public const TYPE_BRAND_INVITE = 'brand_invite';
    public const TYPE_PRODUCT_LOW_STOCK = 'product_low_stock';
    public const TYPE_WEEKLY_STATS = 'weekly_stats';
    public const TYPE_SYSTEM = 'system';
    public const TYPE_PURCHASE_REQUEST_NEW = 'purchase_request_new';
    public const TYPE_PURCHASE_REQUEST_DECIDED = 'purchase_request_decided';
    public const TYPE_PURCHASE_FITTING = 'purchase_fitting';
    public const TYPE_PURCHASE_BOUGHT = 'purchase_bought';
    public const TYPE_PURCHASE_REFUSED = 'purchase_refused';
    public const TYPE_PURCHASE_RETURNED = 'purchase_returned';
    public const TYPE_PURCHASE_DECISION_REMINDER = 'purchase_decision_reminder';
    public const TYPE_PURCHASE_FITTING_REMINDER = 'purchase_fitting_reminder';
    public const TYPE_PURCHASE_WEAR_REMINDER = 'purchase_wear_reminder';

    // Каналы доставки
    public const CHANNEL_INAPP = 'inapp';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_TELEGRAM = 'telegram';
    public const CHANNEL_PUSH = 'push';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'notifications')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $recipient = null;

    #[ORM\Column(length: 50)]
    private ?string $type = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    // Дополнительные данные (id заказа, ссылка, etc.)
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $data = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $dedupeKey = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isRead = false;

    #[ORM\Column(length: 20, options: ['default' => 'inapp'])]
    private string $channel = self::CHANNEL_INAPP;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getRecipient(): ?User { return $this->recipient; }

    public function setRecipient(?User $recipient): static
    {
        $this->recipient = $recipient;
        return $this;
    }

    public function getType(): ?string { return $this->type; }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getTitle(): ?string { return $this->title; }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getBody(): ?string { return $this->body; }

    public function setBody(?string $body): static
    {
        $this->body = $body;
        return $this;
    }

    public function getData(): ?array { return $this->data; }

    public function getSafeAccountUrl(): ?string
    {
        $url = $this->data['url'] ?? null;

        return is_string($url) && preg_match('#^/account/(?:purchases/\d+|wardrobe/wear(?:\?member=\d+)?)$#', $url) ? $url : null;
    }

    public function setData(?array $data): static
    {
        $this->data = $data;
        return $this;
    }

    public function getDedupeKey(): ?string { return $this->dedupeKey; }

    public function setDedupeKey(?string $dedupeKey): static
    {
        $this->dedupeKey = $dedupeKey;
        return $this;
    }

    public function isRead(): bool { return $this->isRead; }

    public function markAsRead(): static
    {
        $this->isRead = true;
        $this->readAt = new \DateTimeImmutable();
        return $this;
    }

    public function getChannel(): string { return $this->channel; }

    public function setChannel(string $channel): static
    {
        $this->channel = $channel;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
}
