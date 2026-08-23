<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FamilyBudgetRepository;
use App\ValueObject\MoneyAmount;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FamilyBudgetRepository::class)]
#[ORM\Table(name: 'family_budget')]
#[ORM\UniqueConstraint(name: 'uniq_family_budget_subject', columns: ['subject_id'])]
class FamilyBudget
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $subject = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $monthlyLimit = '0.00';

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSubject(): ?User { return $this->subject; }
    public function setSubject(User $subject): static { $this->subject = $subject; return $this; }
    public function getMonthlyLimit(): string { return $this->monthlyLimit; }
    public function setMonthlyLimit(string $monthlyLimit): static
    {
        $this->monthlyLimit = MoneyAmount::normalize($monthlyLimit);
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
