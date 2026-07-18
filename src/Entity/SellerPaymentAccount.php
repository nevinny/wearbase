<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SellerPaymentAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Счёт приёма оплаты: связь юр.лица продавца с конкретной платёжкой
 * плюс реквизиты у этого шлюза. У юр.лица может быть несколько счетов
 * (на разных платёжках); один помечается is_primary как основной.
 *
 * secret_encrypted хранит секрет шлюза — шифруется на уровне приложения
 * перед записью; в открытом виде не сохранять.
 */
#[ORM\Entity(repositoryClass: SellerPaymentAccountRepository::class)]
#[ORM\Table(name: 'seller_payment_account')]
#[ORM\UniqueConstraint(name: 'uq_spa_entity_provider', columns: ['legal_entity_id', 'provider_id'])]
#[ORM\HasLifecycleCallbacks]
class SellerPaymentAccount
{
    public const MODE_DIRECT = 'direct';
    public const MODE_MARKETPLACE = 'marketplace';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_DELETED = 'deleted';   // soft-delete (никогда не физический DELETE — см. CLAUDE.md)

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'paymentAccounts')]
    #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'CASCADE')]
    private ?SellerLegalEntity $legalEntity = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'provider_id', nullable: false, onDelete: 'RESTRICT')]
    private ?PaymentProvider $provider = null;

    #[ORM\Column(length: 20, options: ['default' => 'direct'])]
    private string $mode = self::MODE_DIRECT;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $accountRef = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $secretEncrypted = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $config = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isPrimary = false;

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getLegalEntity(): ?SellerLegalEntity { return $this->legalEntity; }
    public function setLegalEntity(?SellerLegalEntity $legalEntity): static { $this->legalEntity = $legalEntity; return $this; }

    public function getProvider(): ?PaymentProvider { return $this->provider; }
    public function setProvider(?PaymentProvider $provider): static { $this->provider = $provider; return $this; }

    public function getMode(): string { return $this->mode; }
    public function setMode(string $mode): static { $this->mode = $mode; return $this; }

    public function getAccountRef(): ?string { return $this->accountRef; }
    public function setAccountRef(?string $accountRef): static { $this->accountRef = $accountRef; return $this; }

    public function getSecretEncrypted(): ?string { return $this->secretEncrypted; }
    public function setSecretEncrypted(?string $secretEncrypted): static { $this->secretEncrypted = $secretEncrypted; return $this; }

    public function getConfig(): ?array { return $this->config; }
    public function setConfig(?array $config): static { $this->config = $config; return $this; }

    public function isPrimary(): bool { return $this->isPrimary; }
    public function setIsPrimary(bool $isPrimary): static { $this->isPrimary = $isPrimary; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    /**
     * Готов ли счёт реально принимать онлайн-оплату. Должен совпадать с тем,
     * что требует PaymentService::createOrderPayment, иначе баннер «зеленеет»
     * над сломанным чекаутом. (Корректность секрета — по непустоте; полная
     * проверка дешифровки делается в рантайме платежа.)
     */
    public function isReadyToAcceptOnline(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->provider?->getCode() === PaymentProvider::CODE_YOOKASSA
            && (string) $this->accountRef !== ''
            && (string) $this->secretEncrypted !== '';
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
