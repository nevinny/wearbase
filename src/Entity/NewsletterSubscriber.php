<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NewsletterSubscriberRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: NewsletterSubscriberRepository::class)]
#[ORM\Table(name: 'newsletter_subscriber')]
class NewsletterSubscriber
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email;

    /** Откуда подписался: footer-subscribe, blog, etc. */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $source = null;

    /** Double opt-in: пока не подтверждён — рассылка не уходит. */
    #[ORM\Column(length: 64, unique: true)]
    private string $confirmToken;

    /** Одноразовый постоянный токен для ссылки отписки в каждом письме. */
    #[ORM\Column(length: 64, unique: true)]
    private string $unsubscribeToken;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTime $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $confirmedAt = null;

    /** Soft-delete: отписанные не удаляются физически. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $unsubscribedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->confirmToken = bin2hex(random_bytes(32));
        $this->unsubscribeToken = bin2hex(random_bytes(32));
    }

    public function getId(): ?int { return $this->id; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getSource(): ?string { return $this->source; }
    public function setSource(?string $source): static { $this->source = $source; return $this; }

    public function getConfirmToken(): string { return $this->confirmToken; }

    public function getUnsubscribeToken(): string { return $this->unsubscribeToken; }

    public function getCreatedAt(): \DateTime { return $this->createdAt; }

    public function getConfirmedAt(): ?\DateTime { return $this->confirmedAt; }

    public function confirm(): static
    {
        $this->confirmedAt ??= new \DateTime();
        $this->unsubscribedAt = null;

        return $this;
    }

    public function getUnsubscribedAt(): ?\DateTime { return $this->unsubscribedAt; }

    public function unsubscribe(): static
    {
        $this->unsubscribedAt ??= new \DateTime();

        return $this;
    }

    /** Возобновление подписки после отписки: новый цикл double opt-in. */
    public function restartOptIn(): static
    {
        $this->confirmToken = bin2hex(random_bytes(32));
        $this->confirmedAt = null;
        $this->unsubscribedAt = null;

        return $this;
    }

    public function isConfirmed(): bool { return $this->confirmedAt !== null; }

    public function isActive(): bool { return $this->confirmedAt !== null && $this->unsubscribedAt === null; }
}
