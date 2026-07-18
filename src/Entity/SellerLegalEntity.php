<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SellerLegalEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Юр.лицо продавца. У одного бренда может быть несколько юр.лиц,
 * которые меняются/устаревают во времени (effective_from..effective_to).
 * Заказ ссылается на то юр.лицо, что было продавцом-of-record на момент сделки.
 *
 * Реквизиты приёма оплаты живут в SellerPaymentAccount (юр.лицо ↔ платёжка),
 * т.к. меняются вместе с юр.лицом и бывают на нескольких платёжках сразу.
 */
#[ORM\Entity(repositoryClass: SellerLegalEntityRepository::class)]
#[ORM\Table(name: 'seller_legal_entity')]
#[ORM\HasLifecycleCallbacks]
class SellerLegalEntity
{
    public const FORM_OOO = 'ooo';
    public const FORM_IP = 'ip';
    public const FORM_SELF_EMPLOYED = 'self_employed';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_DELETED = 'deleted';   // soft-delete (никогда не физический DELETE — см. CLAUDE.md)

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'brand_id', nullable: false, onDelete: 'CASCADE')]
    private ?Brand $brand = null;

    #[ORM\Column(length: 20, options: ['default' => 'ooo'])]
    private string $legalForm = self::FORM_OOO;

    #[ORM\Column(length: 255)]
    private ?string $legalName = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $inn = null;

    #[ORM\Column(length: 9, nullable: true)]
    private ?string $kpp = null;

    #[ORM\Column(length: 15, nullable: true)]
    private ?string $ogrn = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $legalAddress = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isIdentified = false;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $effectiveFrom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $effectiveTo = null;

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, SellerPaymentAccount>
     */
    #[ORM\OneToMany(targetEntity: SellerPaymentAccount::class, mappedBy: 'legalEntity', cascade: ['persist'], orphanRemoval: true)]
    private Collection $paymentAccounts;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->paymentAccounts = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getBrand(): ?Brand { return $this->brand; }
    public function setBrand(?Brand $brand): static { $this->brand = $brand; return $this; }

    public function getLegalForm(): string { return $this->legalForm; }
    public function setLegalForm(string $legalForm): static { $this->legalForm = $legalForm; return $this; }

    public function getLegalName(): ?string { return $this->legalName; }
    public function setLegalName(string $legalName): static { $this->legalName = $legalName; return $this; }

    public function getInn(): ?string { return $this->inn; }
    public function setInn(?string $inn): static { $this->inn = $inn; return $this; }

    public function getKpp(): ?string { return $this->kpp; }
    public function setKpp(?string $kpp): static { $this->kpp = $kpp; return $this; }

    public function getOgrn(): ?string { return $this->ogrn; }
    public function setOgrn(?string $ogrn): static { $this->ogrn = $ogrn; return $this; }

    public function getLegalAddress(): ?string { return $this->legalAddress; }
    public function setLegalAddress(?string $legalAddress): static { $this->legalAddress = $legalAddress; return $this; }

    public function isIdentified(): bool { return $this->isIdentified; }
    public function setIsIdentified(bool $isIdentified): static { $this->isIdentified = $isIdentified; return $this; }

    public function getEffectiveFrom(): ?\DateTimeImmutable { return $this->effectiveFrom; }
    public function setEffectiveFrom(?\DateTimeImmutable $effectiveFrom): static { $this->effectiveFrom = $effectiveFrom; return $this; }

    public function getEffectiveTo(): ?\DateTimeImmutable { return $this->effectiveTo; }
    public function setEffectiveTo(?\DateTimeImmutable $effectiveTo): static { $this->effectiveTo = $effectiveTo; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, SellerPaymentAccount> */
    public function getPaymentAccounts(): Collection { return $this->paymentAccounts; }

    public function addPaymentAccount(SellerPaymentAccount $account): static
    {
        if (!$this->paymentAccounts->contains($account)) {
            $this->paymentAccounts->add($account);
            $account->setLegalEntity($this);
        }
        return $this;
    }

    public function removePaymentAccount(SellerPaymentAccount $account): static
    {
        if ($this->paymentAccounts->removeElement($account) && $account->getLegalEntity() === $this) {
            $account->setLegalEntity(null);
        }
        return $this;
    }

    /** Основной счёт приёма оплаты (is_primary, active), если настроен. */
    public function getPrimaryPaymentAccount(): ?SellerPaymentAccount
    {
        foreach ($this->paymentAccounts as $account) {
            if ($account->isPrimary() && $account->getStatus() === SellerPaymentAccount::STATUS_ACTIVE) {
                return $account;
            }
        }
        return null;
    }

    /** Основной счёт, реально готовый принимать онлайн-оплату (реквизиты заполнены). */
    public function getReadyPrimaryAccount(): ?SellerPaymentAccount
    {
        $primary = $this->getPrimaryPaymentAccount();

        return $primary !== null && $primary->isReadyToAcceptOnline() ? $primary : null;
    }

    /**
     * Счета, видимые в ЛК (не удалённые soft-delete). Для показа/подсчёта подключённых
     * провайдеров — удалённые не должны «занимать» платёжку.
     *
     * @return list<SellerPaymentAccount>
     */
    public function getVisiblePaymentAccounts(): array
    {
        return array_values(array_filter(
            $this->paymentAccounts->toArray(),
            static fn (SellerPaymentAccount $a): bool => $a->getStatus() !== SellerPaymentAccount::STATUS_DELETED,
        ));
    }
}
