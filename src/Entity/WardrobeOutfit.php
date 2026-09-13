<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WardrobeOutfitRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WardrobeOutfitRepository::class)]
#[ORM\Table(name: 'wardrobe_outfit')]
#[ORM\Index(columns: ['user_id', 'created_at'], name: 'idx_wardrobe_outfit_user_created')]
class WardrobeOutfit
{
    public const REACTION_LIKE = 'like';
    public const REACTION_DISLIKE = 'dislike';
    public const REACTION_WORN = 'worn';
    public const REACTIONS = [self::REACTION_LIKE, self::REACTION_DISLIKE, self::REACTION_WORN];

    // Поводы ночного пакетного конвейера (app:wardrobe:daily-outfits + WardrobeDailyController):
    // ключ едет в occasion и в payload прод-API, значение — request-текст для промпта/prompt-поля.
    public const OCCASION_WORK = 'work';
    public const OCCASION_THEATER = 'theater';
    public const OCCASION_WALK = 'walk';
    public const OCCASION_MEETING = 'meeting';
    public const DAILY_OCCASIONS = [
        self::OCCASION_WORK => 'Образ на работу',
        self::OCCASION_THEATER => 'Образ в театр',
        self::OCCASION_WALK => 'Образ на прогулку',
        self::OCCASION_MEETING => 'Образ на встречу',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $wardrobeOwner = null;

    #[ORM\Column(length: 300)]
    private string $prompt = '';

    #[ORM\Column(length: 100)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $explanation = null;

    /** @var array<int, array{id:int,category:?string,color:?string,styles:string[]}> */
    #[ORM\Column(type: Types::JSON)]
    private array $items = [];

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $reaction = null;

    // Повод ночного пакетного конвейера (см. DAILY_OCCASIONS); null у образов,
    // собранных интерактивно через account_wardrobe_outfits.
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $occasion = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reactedAt = null;

    // Soft-delete: используется только идемпотентной заменой пакетного конвейера
    // (тот же гардероб+повод+день) — правило проекта запрещает физический DELETE.
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }
    public function getWardrobeOwner(): ?User { return $this->wardrobeOwner; }
    public function setWardrobeOwner(User $owner): static { $this->wardrobeOwner = $owner; return $this; }
    public function getPrompt(): string { return $this->prompt; }
    public function setPrompt(string $prompt): static { $this->prompt = $prompt; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    public function getExplanation(): ?string { return $this->explanation; }
    public function setExplanation(?string $explanation): static { $this->explanation = $explanation; return $this; }
    public function getItems(): array { return $this->items; }
    public function setItems(array $items): static { $this->items = $items; return $this; }
    public function getReaction(): ?string { return $this->reaction; }
    public function getOccasion(): ?string { return $this->occasion; }
    public function setOccasion(?string $occasion): static { $this->occasion = $occasion; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getReactedAt(): ?\DateTimeImmutable { return $this->reactedAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }

    public function react(string $reaction): void
    {
        if (!in_array($reaction, self::REACTIONS, true)) {
            throw new \InvalidArgumentException('Неизвестная реакция на образ');
        }
        $this->reaction = $reaction;
        $this->reactedAt = new \DateTimeImmutable();
    }
}
