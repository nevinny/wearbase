<?php

namespace App\Entity;

use App\Repository\SeoCompetitorScanRepository;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;

/**
 * Одна строка на проверку SEO-фразы (docs/seo_competitor_content.md,
 * app:seo:competitor-scan): выдача конкурентов + извлечённые темы/структура
 * (gap_summary, НЕ факты конкурента — юридический риск пересказа) + приоритет.
 *
 * status: pending (только резолвлена фраза) → scanned (SERP+фетч сделаны) →
 * analyzed (LLM извлёк темы) → routed (рекомендация роутинга построена) | error.
 * Обычная строка, БЕЗ soft-delete машинерии — внутренняя рабочая таблица
 * конвейера, без ручного DELETE по построению (см. CLAUDE.md).
 */
#[ORM\Entity(repositoryClass: SeoCompetitorScanRepository::class)]
#[ORM\Table(name: 'seo_competitor_scan')]
#[ORM\Index(name: 'idx_seo_competitor_scan_keyword', columns: ['keyword'])]
class SeoCompetitorScan
{
    use Created;

    public const SOURCE_WORDSTAT = 'wordstat';
    public const SOURCE_GSC      = 'gsc';
    public const SOURCE_YANDEX   = 'yandex';
    public const SOURCE_BOTH     = 'both';

    public const INTENT_GEO_CATEGORY      = 'geo_category';
    public const INTENT_REPLACE_COMPARISON = 'replace_comparison';
    public const INTENT_OTHER             = 'other';

    public const STATUS_PENDING  = 'pending';
    public const STATUS_SCANNED  = 'scanned';
    public const STATUS_ANALYZED = 'analyzed';
    public const STATUS_ROUTED   = 'routed';
    public const STATUS_ERROR    = 'error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $keyword = '';

    #[ORM\Column(length: 16)]
    private string $demandSource = self::SOURCE_BOTH;

    #[ORM\Column(length: 30)]
    private string $intentGroup = self::INTENT_OTHER;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $ourUrl = null;

    /**
     * @var list<array{url:string,position:int,page_type:string,competitor_article_id:?int}>
     */
    #[ORM\Column(type: 'json')]
    private array $serpResults = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $gapSummary = null;

    #[ORM\Column(nullable: true)]
    private ?float $priorityScore = null;

    #[ORM\Column(length: 16, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKeyword(): string
    {
        return $this->keyword;
    }

    public function setKeyword(string $keyword): self
    {
        $this->keyword = mb_substr($keyword, 0, 255);

        return $this;
    }

    public function getDemandSource(): string
    {
        return $this->demandSource;
    }

    public function setDemandSource(string $demandSource): self
    {
        $this->demandSource = $demandSource;

        return $this;
    }

    public function getIntentGroup(): string
    {
        return $this->intentGroup;
    }

    public function setIntentGroup(string $intentGroup): self
    {
        $this->intentGroup = $intentGroup;

        return $this;
    }

    public function getOurUrl(): ?string
    {
        return $this->ourUrl;
    }

    public function setOurUrl(?string $ourUrl): self
    {
        $this->ourUrl = $ourUrl !== null ? mb_substr($ourUrl, 0, 512) : null;

        return $this;
    }

    /** @return list<array{url:string,position:int,page_type:string,competitor_article_id:?int}> */
    public function getSerpResults(): array
    {
        return $this->serpResults;
    }

    /** @param list<array{url:string,position:int,page_type:string,competitor_article_id:?int}> $serpResults */
    public function setSerpResults(array $serpResults): self
    {
        $this->serpResults = $serpResults;

        return $this;
    }

    public function getGapSummary(): ?string
    {
        return $this->gapSummary;
    }

    public function setGapSummary(?string $gapSummary): self
    {
        $this->gapSummary = $gapSummary;

        return $this;
    }

    public function getPriorityScore(): ?float
    {
        return $this->priorityScore;
    }

    public function setPriorityScore(?float $priorityScore): self
    {
        $this->priorityScore = $priorityScore;

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

    public function getCheckedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function setCheckedAt(?\DateTimeImmutable $checkedAt): self
    {
        $this->checkedAt = $checkedAt;

        return $this;
    }
}
