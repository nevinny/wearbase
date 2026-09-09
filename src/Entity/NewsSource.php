<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NewsSourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;

#[ORM\Entity(repositoryClass: NewsSourceRepository::class)]
#[ORM\Table(name: 'news_source')]
#[ORM\HasLifecycleCallbacks]
class NewsSource
{
    use Created;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $name;

    #[ORM\Column(name: 'feed_url', length: 512, unique: true)]
    private string $feedUrl;

    /**
     * Правовой режим (TosMode): facts_only — только факты; forbidden — жёсткий skip.
     * Хранится строкой (enumType), чтобы конфиг источников был декларативным
     * (_docs/news-sources-tos.md, открытый вопрос №5).
     */
    #[ORM\Column(name: 'tos_mode', length: 16, enumType: \App\Enum\TosMode::class, options: ['default' => 'facts_only'])]
    private \App\Enum\TosMode $tosMode = \App\Enum\TosMode::FactsOnly;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** Подсказка рубрикатору из профиля источника («дети», «мода», …). Nullable. */
    #[ORM\Column(name: 'rubric_hint', length: 64, nullable: true)]
    private ?string $rubricHint = null;

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getFeedUrl(): string { return $this->feedUrl; }
    public function setFeedUrl(string $feedUrl): static { $this->feedUrl = $feedUrl; return $this; }

    public function getTosMode(): \App\Enum\TosMode { return $this->tosMode; }
    public function setTosMode(\App\Enum\TosMode $tosMode): static { $this->tosMode = $tosMode; return $this; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }

    public function getRubricHint(): ?string { return $this->rubricHint; }
    public function setRubricHint(?string $rubricHint): static { $this->rubricHint = $rubricHint; return $this; }

    public function __toString(): string
    {
        return $this->name;
    }
}
