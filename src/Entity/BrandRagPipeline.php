<?php

namespace App\Entity;

use App\Repository\BrandRagPipelineRepository;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;

/**
 * Состояние RAG-пайплайна для бренда: pending → scraped → embedded → done
 * (+ *_failed на каждом этапе; ветки deferred/review). Ведёт finder-запросы
 * команд-воркеров и переживает
 * перезапуски многодневного прогона. Отдельная таблица, чтобы не раздувать Brand
 * восемью per-stage колонками.
 */
#[ORM\Entity(repositoryClass: BrandRagPipelineRepository::class)]
#[ORM\Table(name: 'brand_rag_pipeline')]
class BrandRagPipeline
{
    use Created;

    public const STATUS_PENDING        = 'pending';
    public const STATUS_SCRAPED        = 'scraped';
    public const STATUS_EMBEDDED       = 'embedded';
    public const STATUS_DONE           = 'done';
    public const STATUS_SCRAPE_FAILED  = 'scrape_failed';
    public const STATUS_EMBED_FAILED   = 'embed_failed';
    public const STATUS_GENERATE_FAILED = 'generate_failed';
    /** Корпус не прошёл gate при --grounded-only: ждём дозревания (новые URL → fetch вернёт в scraped). */
    public const STATUS_DEFERRED        = 'deferred';
    /** Контент — отказ модели (нет/чужой корпус): на ручную верификацию в админке, НЕ публикуем. */
    public const STATUS_REVIEW          = 'review';

    public const KW_FOUND     = 'found';
    public const KW_NOT_FOUND = 'not_found';

    public const ATTR_DONE    = 'done';
    public const ATTR_SKIPPED = 'skipped';
    public const ATTR_FAILED  = 'failed';

    public const CRAWL_DONE    = 'done';
    public const CRAWL_SKIPPED = 'skipped'; // нет own_site — краулить нечего
    public const CRAWL_FAILED  = 'failed';

    public const FAQ_DONE    = 'done';
    /** У бренда нет ключевиков — FAQ не из чего генерить (вопросы «из головы» = анти-SEO). Не блокирует публикацию. */
    public const FAQ_SKIPPED = 'skipped';
    public const FAQ_FAILED  = 'failed';

    public const LOGO_FOUND     = 'found';
    public const LOGO_NOT_FOUND = 'not_found'; // страницы перебраны, годного лого нет (терминально без --force)
    public const LOGO_SKIPPED   = 'skipped';   // нет ни одного URL-кандидата (own_site/website/marketplace)
    public const LOGO_FAILED    = 'failed';    // сетевая/HTTP-ошибка — повторяемо

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Brand $brand = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    /** Ручной приоритет в очереди генерации: чем больше — тем раньше берётся (default 0). */
    #[ORM\Column(options: ['default' => 0])]
    private int $priority = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $scrapedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $embeddedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $generatedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $scrapeAttempts = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $embedAttempts = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $generateAttempts = 0;

    /** Сколько пригодных документов найдено при скрейпе. */
    #[ORM\Column(options: ['default' => 0])]
    private int $sourceCount = 0;

    /** Топовый cosine-score retrieval (аудит качества grounding). */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $topRetrievalScore = null;

    /** Использовался ли RAG-контекст при генерации (иначе legacy-fallback). */
    #[ORM\Column(options: ['default' => false])]
    private bool $grounded = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    /** Есть ли у бренда собственный сайт: null=не проверяли, true=есть, false=нет. */
    #[ORM\Column(nullable: true)]
    private ?bool $hasOwnSite = null;

    /** Когда отработал discover (наполнил brand_source_url). */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $discoveredAt = null;

    /** Исход опроса Wordstat: null=никогда не опрашивали | found | not_found. */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $keywordsStatus = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $keywordsCheckedAt = null;

    /** Исход ингеста Wildberries: null=не обрабатывали | done | no_products | error. */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $wbStatus = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $wbCheckedAt = null;

    /** Исход краула сайта: null=не краулили | done | skipped (нет own_site) | failed. */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $crawlStatus = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $crawledAt = null;

    /** Исход извлечения атрибутов (стадия extract): null=не извлекали | done | skipped | failed. */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $attributesStatus = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $extractedAt = null;

    /** Исход генерации FAQ: null=не генерили | done | skipped (нет ключевиков) | failed. */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $faqStatus = null;

    /** Исход поиска логотипа (стадия logo): null=не искали | found | not_found | skipped | failed. */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $logoStatus = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $logoCheckedAt = null;

    /** Когда бренд доставлен на прод агентом-пушем (null = не доставлен). */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $pushedAt = null;

    /** Когда доставляемые данные менялись (обогащение после пуша). Триггер ре-доставки:
     *  push берёт бренд, если contentChangedAt > pushedAt (см. findReadyToPush). */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $contentChangedAt = null;

    /** Флаг форс-регенерации из loss-ветки closed-loop: ставит evaluate-experiments,
     *  потребляет generate-content --regen-flagged (затем сбрасывает). */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $regenRequestedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $pushAttempts = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $pushError = null;

    /**
     * Единый предикат готовности к публикации (использует агент-пуш):
     * контент сгенерирован, meta заполнена, FAQ отработал (или законно пропущен),
     * Wordstat опрошен (not_found = нишевый, не блокирует).
     */
    public function isPublishReady(): bool
    {
        $b = $this->brand;
        if ($b === null) {
            return false;
        }

        return trim((string) $b->getDescription()) !== ''
            && trim((string) $b->getMetaTitle()) !== ''
            && trim((string) $b->getMetaDescription()) !== ''
            && $this->status === self::STATUS_DONE
            && in_array($this->faqStatus, [self::FAQ_DONE, self::FAQ_SKIPPED], true)
            && in_array($this->keywordsStatus, [self::KW_FOUND, self::KW_NOT_FOUND], true);
    }

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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Доменный переход «эмбеддинг завершён». Инкапсулирует guard против лоссового демоута:
     * готовый (done) бренд НЕ откатывается в embedded при ре-эмбеде — иначе он выпадает из
     * очереди генерации по статусу и застревает (инцидент 06-06). Корпус обновился →
     * регенерацию запрашивает closed-loop через regenRequestedAt, а не молчаливый демоут.
     * Освежаем только embeddedAt (аудит «когда переэмбедили»).
     *
     * Первый шаг переноса переходов статуса в домен (раньше каждая команда дёргала
     * setStatus() напрямую — машина состояний была размазана, инварианты не держались).
     */
    public function markEmbedded(): self
    {
        if ($this->status !== self::STATUS_DONE) {
            $this->status = self::STATUS_EMBEDDED;
        }
        $this->embeddedAt = new \DateTime();
        $this->lastError = null;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    public function getScrapedAt(): ?\DateTimeInterface
    {
        return $this->scrapedAt;
    }

    public function setScrapedAt(?\DateTimeInterface $at): self
    {
        $this->scrapedAt = $at;
        return $this;
    }

    public function getEmbeddedAt(): ?\DateTimeInterface
    {
        return $this->embeddedAt;
    }

    public function setEmbeddedAt(?\DateTimeInterface $at): self
    {
        $this->embeddedAt = $at;
        return $this;
    }

    public function getGeneratedAt(): ?\DateTimeInterface
    {
        return $this->generatedAt;
    }

    public function setGeneratedAt(?\DateTimeInterface $at): self
    {
        $this->generatedAt = $at;
        return $this;
    }

    public function getScrapeAttempts(): int
    {
        return $this->scrapeAttempts;
    }

    public function setScrapeAttempts(int $n): self
    {
        $this->scrapeAttempts = $n;
        return $this;
    }

    public function getEmbedAttempts(): int
    {
        return $this->embedAttempts;
    }

    public function setEmbedAttempts(int $n): self
    {
        $this->embedAttempts = $n;
        return $this;
    }

    public function getGenerateAttempts(): int
    {
        return $this->generateAttempts;
    }

    public function setGenerateAttempts(int $n): self
    {
        $this->generateAttempts = $n;
        return $this;
    }

    public function getSourceCount(): int
    {
        return $this->sourceCount;
    }

    public function setSourceCount(int $n): self
    {
        $this->sourceCount = $n;
        return $this;
    }

    public function getTopRetrievalScore(): ?float
    {
        return $this->topRetrievalScore;
    }

    public function setTopRetrievalScore(?float $score): self
    {
        $this->topRetrievalScore = $score;
        return $this;
    }

    public function isGrounded(): bool
    {
        return $this->grounded;
    }

    public function setGrounded(bool $grounded): self
    {
        $this->grounded = $grounded;
        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $error): self
    {
        $this->lastError = $error;
        return $this;
    }

    public function getHasOwnSite(): ?bool
    {
        return $this->hasOwnSite;
    }

    public function setHasOwnSite(?bool $hasOwnSite): self
    {
        $this->hasOwnSite = $hasOwnSite;
        return $this;
    }

    public function getDiscoveredAt(): ?\DateTimeInterface
    {
        return $this->discoveredAt;
    }

    public function setDiscoveredAt(?\DateTimeInterface $at): self
    {
        $this->discoveredAt = $at;
        return $this;
    }

    public function getKeywordsStatus(): ?string
    {
        return $this->keywordsStatus;
    }

    public function setKeywordsStatus(?string $status): self
    {
        $this->keywordsStatus = $status;
        return $this;
    }

    public function getKeywordsCheckedAt(): ?\DateTimeInterface
    {
        return $this->keywordsCheckedAt;
    }

    public function setKeywordsCheckedAt(?\DateTimeInterface $at): self
    {
        $this->keywordsCheckedAt = $at;
        return $this;
    }

    public function getAttributesStatus(): ?string
    {
        return $this->attributesStatus;
    }

    public function setAttributesStatus(?string $status): self
    {
        $this->attributesStatus = $status;
        return $this;
    }

    public function getExtractedAt(): ?\DateTimeInterface
    {
        return $this->extractedAt;
    }

    public function setExtractedAt(?\DateTimeInterface $at): self
    {
        $this->extractedAt = $at;
        return $this;
    }

    public function getWbStatus(): ?string
    {
        return $this->wbStatus;
    }

    public function setWbStatus(?string $status): self
    {
        $this->wbStatus = $status;
        return $this;
    }

    public function getWbCheckedAt(): ?\DateTimeInterface
    {
        return $this->wbCheckedAt;
    }

    public function setWbCheckedAt(?\DateTimeInterface $at): self
    {
        $this->wbCheckedAt = $at;
        return $this;
    }

    public function getCrawlStatus(): ?string
    {
        return $this->crawlStatus;
    }

    public function setCrawlStatus(?string $status): self
    {
        $this->crawlStatus = $status;
        return $this;
    }

    public function getCrawledAt(): ?\DateTimeInterface
    {
        return $this->crawledAt;
    }

    public function setCrawledAt(?\DateTimeInterface $at): self
    {
        $this->crawledAt = $at;
        return $this;
    }

    public function getFaqStatus(): ?string
    {
        return $this->faqStatus;
    }

    public function setFaqStatus(?string $status): self
    {
        $this->faqStatus = $status;
        return $this;
    }

    public function getLogoStatus(): ?string
    {
        return $this->logoStatus;
    }

    public function setLogoStatus(?string $status): self
    {
        $this->logoStatus = $status;
        return $this;
    }

    public function getLogoCheckedAt(): ?\DateTimeInterface
    {
        return $this->logoCheckedAt;
    }

    public function setLogoCheckedAt(?\DateTimeInterface $at): self
    {
        $this->logoCheckedAt = $at;
        return $this;
    }

    public function getPushedAt(): ?\DateTimeInterface
    {
        return $this->pushedAt;
    }

    public function setPushedAt(?\DateTimeInterface $at): self
    {
        $this->pushedAt = $at;
        return $this;
    }

    public function getContentChangedAt(): ?\DateTimeInterface
    {
        return $this->contentChangedAt;
    }

    public function getRegenRequestedAt(): ?\DateTimeInterface
    {
        return $this->regenRequestedAt;
    }

    public function setRegenRequestedAt(?\DateTimeInterface $at): self
    {
        $this->regenRequestedAt = $at;
        return $this;
    }

    public function setContentChangedAt(?\DateTimeInterface $at): self
    {
        $this->contentChangedAt = $at;
        return $this;
    }

    public function getPushAttempts(): int
    {
        return $this->pushAttempts;
    }

    public function setPushAttempts(int $n): self
    {
        $this->pushAttempts = $n;
        return $this;
    }

    public function getPushError(): ?string
    {
        return $this->pushError;
    }

    public function setPushError(?string $error): self
    {
        $this->pushError = $error;
        return $this;
    }
}
