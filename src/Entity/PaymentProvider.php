<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PaymentProviderRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Справочник поддерживаемых платёжных шлюзов (ЮKassa, Т-Бизнес, CloudPayments, СБП).
 * Бренд подключает один из них на своём юр.лице через SellerPaymentAccount.
 */
#[ORM\Entity(repositoryClass: PaymentProviderRepository::class)]
#[ORM\Table(name: 'payment_provider')]
#[ORM\UniqueConstraint(name: 'uq_payment_provider_code', columns: ['code'])]
class PaymentProvider
{
    public const CODE_YOOKASSA = 'yookassa';
    public const CODE_TINKOFF = 'tinkoff';
    public const CODE_CLOUDPAYMENTS = 'cloudpayments';
    public const CODE_SBP = 'sbp';
    public const CODE_SBER = 'sber';
    public const CODE_ROBOKASSA = 'robokassa';
    public const CODE_PAYSELECTION = 'payselection';
    public const CODE_PAYKEEPER = 'paykeeper';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private ?string $code = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $supportsDirect = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $supportsMarketplace = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }

    public function getCode(): ?string { return $this->code; }
    public function setCode(string $code): static { $this->code = $code; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function supportsDirect(): bool { return $this->supportsDirect; }
    public function setSupportsDirect(bool $supportsDirect): static { $this->supportsDirect = $supportsDirect; return $this; }

    public function supportsMarketplace(): bool { return $this->supportsMarketplace; }
    public function setSupportsMarketplace(bool $supportsMarketplace): static { $this->supportsMarketplace = $supportsMarketplace; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): static { $this->sortOrder = $sortOrder; return $this; }

    public function __toString(): string { return $this->name ?? $this->code ?? ''; }
}
