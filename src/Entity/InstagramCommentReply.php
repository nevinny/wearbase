<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Ответ в директ на комментарий с кодовым словом. Строка создаётся ДО отправки (status=pending):
 * UNIQUE(comment_id) исключает двойную отправку, а упавший посреди отправки процесс не повторит её —
 * лучше потерять один ответ, чем написать человеку дважды.
 */
#[ORM\Entity]
#[ORM\Table(name: 'instagram_comment_reply')]
#[ORM\UniqueConstraint(name: 'uniq_icr_comment', columns: ['comment_id'])]
#[ORM\Index(name: 'idx_icr_post', columns: ['post_id'])]
class InstagramCommentReply
{
    public const STATUS_PENDING  = 'pending';
    /** Ушёл в IG. */
    public const STATUS_SENT     = 'sent';
    /** Временная ошибка (сеть, лимит) — повтор до MAX_ATTEMPTS с паузой. */
    public const STATUS_FAILED   = 'failed';
    /** Постоянная ошибка (окно 7 дней, комментарий удалён, текст не влезает) — не повторяем. */
    public const STATUS_REJECTED = 'rejected';

    public const MAX_ATTEMPTS = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SocialPost $post;

    #[ORM\Column(length: 64)]
    private string $commentId;

    #[ORM\Column(length: 16, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    public function __construct(SocialPost $post, string $commentId)
    {
        $this->post = $post;
        $this->commentId = $commentId;
        $this->createdAt = $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getPost(): SocialPost { return $this->post; }
    public function getCommentId(): string { return $this->commentId; }
    public function getStatus(): string { return $this->status; }
    public function getAttempts(): int { return $this->attempts; }
    public function getError(): ?string { return $this->error; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }

    /** Начало попытки: счётчик растёт до отправки, чтобы сбой на полпути тоже считался. */
    public function startAttempt(): void
    {
        $this->status = self::STATUS_PENDING;
        $this->attempts++;
        $this->touch();
    }

    public function markSent(): void
    {
        $this->status = self::STATUS_SENT;
        $this->error = null;
        $this->touch();
    }

    public function markFailed(string $error, bool $permanent): void
    {
        $this->status = $permanent || $this->attempts >= self::MAX_ATTEMPTS ? self::STATUS_REJECTED : self::STATUS_FAILED;
        $this->error = mb_substr($error, 0, 1000);
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTime();
    }
}
