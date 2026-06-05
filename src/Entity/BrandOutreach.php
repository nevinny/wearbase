<?php

namespace App\Entity;

use App\Repository\BrandOutreachRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Воронка email-активации владельца бренда: одна широкая строка на бренд
 * (sent→opened→clicked→unsubscribed/bounced; claim/subscription корреляцией
 * по brand_id + created_at > sent_at). Suppression — ПО EMAIL.
 * Дизайн: tasktracker «email-активация владельцев», 2026-06-05.
 */
#[ORM\Entity(repositoryClass: BrandOutreachRepository::class)]
#[ORM\Table(name: 'brand_outreach')]
#[ORM\UniqueConstraint(name: 'uniq_outreach_brand', columns: ['brand_id'])]
#[ORM\UniqueConstraint(name: 'uniq_outreach_token', columns: ['send_token'])]
#[ORM\Index(name: 'idx_outreach_email', columns: ['email'])]
#[ORM\Index(name: 'idx_outreach_sent_at', columns: ['sent_at'])]
class BrandOutreach
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Brand $brand = null;

    #[ORM\Column(length: 255)]
    private string $email = '';

    #[ORM\Column(length: 32)]
    private string $sendToken = '';

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $sentAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $firstOpenedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $openCount = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $firstClickedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $clickCount = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $unsubscribedAt = null;

    /** ТОЛЬКО hard bounce (suppression); soft → lastError. */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $bouncedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $lastError = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBrand(): ?Brand
    {
        return $this->brand;
    }

    public function setBrand(?Brand $brand): self
    {
        $this->brand = $brand;
        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getSendToken(): string
    {
        return $this->sendToken;
    }

    public function setSendToken(string $token): self
    {
        $this->sendToken = $token;
        return $this;
    }

    public function getSentAt(): ?\DateTimeInterface
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeInterface $at): self
    {
        $this->sentAt = $at;
        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $n): self
    {
        $this->attempts = $n;
        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $error): self
    {
        $this->lastError = $error !== null ? mb_substr($error, 0, 500) : null;
        return $this;
    }

    public function getUnsubscribedAt(): ?\DateTimeInterface
    {
        return $this->unsubscribedAt;
    }

    public function getBouncedAt(): ?\DateTimeInterface
    {
        return $this->bouncedAt;
    }
}
