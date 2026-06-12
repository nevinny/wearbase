<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OfferDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Иммутабельная версия оферты/политики. Опубликованную версию не редактируем —
 * новая редакция = новая запись с новым version/content_hash/effective_from.
 */
#[ORM\Entity(repositoryClass: OfferDocumentRepository::class)]
#[ORM\Table(name: 'offer_document')]
#[ORM\UniqueConstraint(name: 'uq_offer_type_locale_version', columns: ['type', 'locale', 'version'])]
class OfferDocument
{
    public const TYPE_BUYER_OFFER = 'buyer_offer';
    public const TYPE_SELLER_OFFER = 'seller_offer';
    public const TYPE_PRIVACY = 'privacy';
    public const TYPE_RETURNS = 'returns';
    public const TYPE_PLATFORM_RULES = 'platform_rules';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private string $type = self::TYPE_BUYER_OFFER;

    #[ORM\Column(length: 5, options: ['default' => 'ru'])]
    private string $locale = 'ru';

    #[ORM\Column(length: 20)]
    private ?string $version = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $content = null;

    #[ORM\Column(length: 64)]
    private ?string $contentHash = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $effectiveFrom = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $requiresReacceptance = false;

    #[ORM\Column(length: 20, options: ['default' => 'draft'])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    /** sha256 от текста — для фиксации неизменности опубликованной версии. */
    public function computeHash(): string
    {
        return hash('sha256', (string) $this->content);
    }

    public function getId(): ?int { return $this->id; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getLocale(): string { return $this->locale; }
    public function setLocale(string $locale): static { $this->locale = $locale; return $this; }

    public function getVersion(): ?string { return $this->version; }
    public function setVersion(string $version): static { $this->version = $version; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getContent(): ?string { return $this->content; }
    public function setContent(string $content): static { $this->content = $content; return $this; }

    public function getContentHash(): ?string { return $this->contentHash; }
    public function setContentHash(string $contentHash): static { $this->contentHash = $contentHash; return $this; }

    public function getEffectiveFrom(): ?\DateTimeImmutable { return $this->effectiveFrom; }
    public function setEffectiveFrom(\DateTimeImmutable $effectiveFrom): static { $this->effectiveFrom = $effectiveFrom; return $this; }

    public function requiresReacceptance(): bool { return $this->requiresReacceptance; }
    public function setRequiresReacceptance(bool $requiresReacceptance): static { $this->requiresReacceptance = $requiresReacceptance; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static { $this->publishedAt = $publishedAt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
