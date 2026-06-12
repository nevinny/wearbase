<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrderStatusHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderStatusHistoryRepository::class)]
class OrderStatusHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'statusHistory')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Order $order = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $fromStatus = null;

    #[ORM\Column(length: 20)]
    private ?string $toStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    // null = системное событие
    #[ORM\ManyToOne]
    private ?User $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getOrder(): ?Order { return $this->order; }

    public function setOrder(?Order $order): static
    {
        $this->order = $order;
        return $this;
    }

    public function getFromStatus(): ?string { return $this->fromStatus; }

    public function setFromStatus(?string $fromStatus): static
    {
        $this->fromStatus = $fromStatus;
        return $this;
    }

    public function getToStatus(): ?string { return $this->toStatus; }

    public function setToStatus(string $toStatus): static
    {
        $this->toStatus = $toStatus;
        return $this;
    }

    public function getComment(): ?string { return $this->comment; }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;
        return $this;
    }

    public function getCreatedBy(): ?User { return $this->createdBy; }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
