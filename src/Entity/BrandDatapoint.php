<?php

namespace App\Entity;

use App\Repository\BrandDatapointRepository;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;

/**
 * Состояние точки данных бренда для краудсорс-валидации («исправить неточность»):
 * полиморфная привязка (target_type+target_id+field) к контактам бренда (скаляры),
 * BrandLink и BrandStore. Строки создаются ЛЕНИВО (при первом голосе/owner-правке);
 * отсутствие строки = enrichment/active по умолчанию.
 *
 * Дизайн: tasktracker «Архитектура: краудсорс-валидация данных бренда».
 */
#[ORM\Entity(repositoryClass: BrandDatapointRepository::class)]
#[ORM\Table(name: 'brand_datapoint')]
#[ORM\UniqueConstraint(name: 'uniq_datapoint', columns: ['brand_id', 'target_type', 'target_id', 'field'])]
#[ORM\Index(name: 'idx_dp_brand', columns: ['brand_id'])]
#[ORM\Index(name: 'idx_dp_queue', columns: ['queued_revalidate_at'])]
class BrandDatapoint
{
    use Created;

    public const TYPE_CONTACT = 'brand_contact'; // скаляры Brand: field=phone|email|address
    public const TYPE_LINK    = 'brand_link';    // field=url
    public const TYPE_STORE   = 'brand_store';   // field=address|phone|workhours
    public const TYPE_ATTRIBUTE = 'brand_attribute'; // извлечённый атрибут, target_id=brand_attribute.id, field=value

    public const PROV_ENRICHMENT      = 'enrichment';
    public const PROV_OWNER           = 'owner';
    public const PROV_CROWD_CONFIRMED = 'crowd_confirmed';

    public const STATE_ACTIVE   = 'active';
    public const STATE_DOUBTFUL = 'doubtful';  // бейдж «данные уточняются», но показываем
    public const STATE_HIDDEN   = 'hidden';    // скрыт со страницы, в очереди ре-обогащения
    public const STATE_PINNED   = 'pinned';    // подтверждён толпой

    /** Допустимые field по типу (валидация vote-endpoint'а). */
    public const FIELDS = [
        self::TYPE_CONTACT   => ['phone', 'email', 'address'],
        self::TYPE_LINK      => ['url'],
        self::TYPE_STORE     => ['address', 'phone', 'workhours'],
        self::TYPE_ATTRIBUTE => ['value'],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Brand $brand = null;

    #[ORM\Column(length: 20)]
    private string $targetType = self::TYPE_CONTACT;

    /** id строки brand_link/brand_store; NULL для скаляров Brand. Мягкий (без FK — полиморфизм). */
    #[ORM\Column(nullable: true)]
    private ?int $targetId = null;

    #[ORM\Column(length: 20)]
    private string $field = '';

    #[ORM\Column(length: 16, options: ['default' => self::PROV_ENRICHMENT])]
    private string $provenance = self::PROV_ENRICHMENT;

    #[ORM\Column(length: 12, options: ['default' => self::STATE_ACTIVE])]
    private string $state = self::STATE_ACTIVE;

    /** Суммы ВЕСОВ голосов (аноним 1, залогинен 3) — накрутка с одного отпечатка не масштабируется. */
    #[ORM\Column(options: ['default' => 0])]
    private int $confirmCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $rejectCount = 0;

    /** reject за скользящее окно (MVP: = rejectCount; пересчёт кроном — полная версия). */
    #[ORM\Column(options: ['default' => 0])]
    private int $rejectWindow = 0;

    /** Owner-правка ИМЕННО этой точки (brand.updated_at не годится — его бьёт enrichment). */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $ownerEditedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $stateChangedAt = null;

    /** Поставлено в очередь ре-обогащения (агент заберёт через GET /api/v1/revalidation-queue). */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $queuedRevalidateAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $revalidatedAt = null;

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

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function setTargetType(string $type): self
    {
        $this->targetType = $type;
        return $this;
    }

    public function getTargetId(): ?int
    {
        return $this->targetId;
    }

    public function setTargetId(?int $id): self
    {
        $this->targetId = $id;
        return $this;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function setField(string $field): self
    {
        $this->field = $field;
        return $this;
    }

    public function getProvenance(): string
    {
        return $this->provenance;
    }

    public function setProvenance(string $provenance): self
    {
        $this->provenance = $provenance;
        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): self
    {
        if ($state !== $this->state) {
            $this->stateChangedAt = new \DateTime();
        }
        $this->state = $state;
        return $this;
    }

    public function getConfirmCount(): int
    {
        return $this->confirmCount;
    }

    public function setConfirmCount(int $n): self
    {
        $this->confirmCount = $n;
        return $this;
    }

    public function getRejectCount(): int
    {
        return $this->rejectCount;
    }

    public function setRejectCount(int $n): self
    {
        $this->rejectCount = $n;
        return $this;
    }

    public function getRejectWindow(): int
    {
        return $this->rejectWindow;
    }

    public function setRejectWindow(int $n): self
    {
        $this->rejectWindow = $n;
        return $this;
    }

    public function getOwnerEditedAt(): ?\DateTimeInterface
    {
        return $this->ownerEditedAt;
    }

    public function setOwnerEditedAt(?\DateTimeInterface $at): self
    {
        $this->ownerEditedAt = $at;
        return $this;
    }

    public function getQueuedRevalidateAt(): ?\DateTimeInterface
    {
        return $this->queuedRevalidateAt;
    }

    public function setQueuedRevalidateAt(?\DateTimeInterface $at): self
    {
        $this->queuedRevalidateAt = $at;
        return $this;
    }

    public function getRevalidatedAt(): ?\DateTimeInterface
    {
        return $this->revalidatedAt;
    }

    public function setRevalidatedAt(?\DateTimeInterface $at): self
    {
        $this->revalidatedAt = $at;
        return $this;
    }
}
