<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NewsItemStatus;
use App\Enum\NewsRubric;
use App\Repository\NewsItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;

#[ORM\Entity(repositoryClass: NewsItemRepository::class)]
#[ORM\Table(name: 'news_item')]
#[ORM\Index(name: 'IDX_news_item_status', columns: ['status'])]
#[ORM\Index(name: 'IDX_news_item_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'UNIQ_news_item_source_guid', columns: ['source_id', 'guid_hash'])]
#[ORM\HasLifecycleCallbacks]
class NewsItem
{
    use Created;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: NewsSource::class)]
    #[ORM\JoinColumn(name: 'source_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private NewsSource $source;

    /** sha256 нормализованного guid/URL — дедупликация в пределах источника. */
    #[ORM\Column(name: 'guid_hash', length: 64)]
    private string $guidHash;

    #[ORM\Column(length: 512)]
    private string $url;

    /** Публичный слаг из транслита заголовка; уникален. Null до ready. */
    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $slug = null;

    /** Заголовок из фида (сырой). */
    #[ORM\Column(length: 512)]
    private string $title;

    /** Имя издания для атрибуции «По материалам …» (денормализация от source). */
    #[ORM\Column(name: 'source_name', length: 255)]
    private string $sourceName;

    /** Ссылка на оригинал для постоянного dofollow. */
    #[ORM\Column(name: 'source_url', length: 512)]
    private string $sourceUrl;

    /** Дата выхода оригинала (из фида). */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $publishedAt = null;

    /** Исходный текст статьи (доказательство трансформации при претензии). */
    #[ORM\Column(name: 'raw_fetched_text', type: Types::TEXT, nullable: true)]
    private ?string $rawFetchedText = null;

    #[ORM\Column(name: 'rewritten_title', length: 512, nullable: true)]
    private ?string $rewrittenTitle = null;

    #[ORM\Column(name: 'rewritten_body', type: Types::TEXT, nullable: true)]
    private ?string $rewrittenBody = null;

    /** Когда item стал ready — кап «готовых к публикации» ≤8/день считается по нему. */
    #[ORM\Column(name: 'ready_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $readyAt = null;

    #[ORM\Column(length: 16, nullable: true, enumType: NewsRubric::class)]
    private ?NewsRubric $rubric = null;

    /** Доля совпадающих 5-грамм с исходником; гейт ≤0.10 (_docs/news-sources-tos.md §2.1). */
    #[ORM\Column(name: 'shingle_score', type: Types::FLOAT, nullable: true)]
    private ?float $shingleScore = null;

    #[ORM\Column(length: 16, enumType: NewsItemStatus::class, options: ['default' => 'discovered'])]
    private NewsItemStatus $status = NewsItemStatus::Discovered;

    /**
     * Карта переходов статус → момент (ATOM): {discovered, fetched, rewritten,
     * ready, published|rejected}. JSON — история без доп-таблицы.
     *
     * @var array<string, string>
     */
    #[ORM\Column(name: 'status_timestamps', type: Types::JSON, nullable: true)]
    private array $statusTimestamps = [];

    /** Почему rejected (шингл-гейт / ручная модерация). */
    #[ORM\Column(name: 'reject_reason', length: 255, nullable: true)]
    private ?string $rejectReason = null;

    public function getId(): ?int { return $this->id; }

    public function getSource(): NewsSource { return $this->source; }
    public function setSource(NewsSource $source): static { $this->source = $source; return $this; }

    public function getGuidHash(): string { return $this->guidHash; }
    public function setGuidHash(string $guidHash): static { $this->guidHash = $guidHash; return $this; }

    public function getUrl(): string { return $this->url; }
    public function setUrl(string $url): static { $this->url = $url; return $this; }

    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(?string $slug): static { $this->slug = $slug; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getSourceName(): string { return $this->sourceName; }
    public function setSourceName(string $sourceName): static { $this->sourceName = $sourceName; return $this; }

    public function getSourceUrl(): string { return $this->sourceUrl; }
    public function setSourceUrl(string $sourceUrl): static { $this->sourceUrl = $sourceUrl; return $this; }

    public function getPublishedAt(): ?\DateTime { return $this->publishedAt; }
    public function setPublishedAt(?\DateTime $publishedAt): static { $this->publishedAt = $publishedAt; return $this; }

    public function getRawFetchedText(): ?string { return $this->rawFetchedText; }
    public function setRawFetchedText(?string $rawFetchedText): static { $this->rawFetchedText = $rawFetchedText; return $this; }

    public function getRewrittenTitle(): ?string { return $this->rewrittenTitle; }
    public function setRewrittenTitle(?string $rewrittenTitle): static { $this->rewrittenTitle = $rewrittenTitle; return $this; }

    public function getRewrittenBody(): ?string { return $this->rewrittenBody; }
    public function setRewrittenBody(?string $rewrittenBody): static { $this->rewrittenBody = $rewrittenBody; return $this; }

    public function getReadyAt(): ?\DateTime { return $this->readyAt; }
    public function setReadyAt(?\DateTime $readyAt): static { $this->readyAt = $readyAt; return $this; }

    public function getRubric(): ?NewsRubric { return $this->rubric; }
    public function setRubric(?NewsRubric $rubric): static { $this->rubric = $rubric; return $this; }

    public function getShingleScore(): ?float { return $this->shingleScore; }
    public function setShingleScore(?float $shingleScore): static { $this->shingleScore = $shingleScore; return $this; }

    public function getStatus(): NewsItemStatus { return $this->status; }

    public function setStatus(NewsItemStatus $status): static
    {
        $this->status = $status;
        $this->statusTimestamps[$status->value] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        if ($status === NewsItemStatus::Ready && $this->readyAt === null) {
            $this->readyAt = new \DateTime();
        }

        return $this;
    }

    /** @return array<string, string> */
    public function getStatusTimestamps(): array { return $this->statusTimestamps; }
    public function setStatusTimestamps(array $statusTimestamps): static { $this->statusTimestamps = $statusTimestamps; return $this; }

    public function getRejectReason(): ?string { return $this->rejectReason; }
    public function setRejectReason(?string $rejectReason): static { $this->rejectReason = $rejectReason; return $this; }

    public function __toString(): string
    {
        return $this->rewrittenTitle ?? $this->title;
    }
}
