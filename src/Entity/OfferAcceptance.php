<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OfferAcceptanceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Факт акцепта оферты пользователем — append-only доказательство:
 * кто, когда, какую версию, каким действием, с какого IP.
 * Акцепт совершается один раз (регистрация); новая редакция с
 * requiresReacceptance требует повторного акцепта (context_type=reaccept).
 */
#[ORM\Entity(repositoryClass: OfferAcceptanceRepository::class)]
#[ORM\Table(name: 'offer_acceptance')]
class OfferAcceptance
{
    public const CONTEXT_REGISTRATION = 'registration';
    public const CONTEXT_REACCEPT = 'reaccept';
    public const CONTEXT_SELLER_ONBOARDING = 'seller_onboarding';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'offer_document_id', nullable: false, onDelete: 'RESTRICT')]
    private ?OfferDocument $offerDocument = null;

    #[ORM\Column(length: 30)]
    private string $contextType = self::CONTEXT_REGISTRATION;

    #[ORM\Column]
    private \DateTimeImmutable $acceptedAt;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    public function __construct()
    {
        $this->acceptedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getOfferDocument(): ?OfferDocument { return $this->offerDocument; }
    public function setOfferDocument(?OfferDocument $offerDocument): static { $this->offerDocument = $offerDocument; return $this; }

    public function getContextType(): string { return $this->contextType; }
    public function setContextType(string $contextType): static { $this->contextType = $contextType; return $this; }

    public function getAcceptedAt(): \DateTimeImmutable { return $this->acceptedAt; }
    public function setAcceptedAt(\DateTimeImmutable $acceptedAt): static { $this->acceptedAt = $acceptedAt; return $this; }

    public function getIp(): ?string { return $this->ip; }
    public function setIp(?string $ip): static { $this->ip = $ip; return $this; }

    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $userAgent): static { $this->userAgent = $userAgent; return $this; }
}
