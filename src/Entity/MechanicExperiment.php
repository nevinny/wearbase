<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MechanicExperimentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Эксперимент над МЕХАНИКОЙ сайта (не контентом): системный контур
 * «гипотеза → изменение механики → замер → вывод» (docs/mechanic_experiments.md).
 *
 * Отличие от brand_content_revision (контент-петля): здесь единица — правка ПОВЕДЕНИЯ
 * страницы (один CTA вместо нескольких, порядок блоков, подборки вместо фильтров), а не
 * текста бренда. Саму Twig-правку MVP НЕ автоматизирует — эксперимент фиксирует что/где/когда
 * менялось (target) и меряет эффект diff-in-diff по когортам A/B. Правку вносит владелец/сессия.
 *
 * Статус-машина: proposed → running → measured → adopted | rolled_back.
 * ICE (impact×confidence×ease, 1-10) денормализован в ice_score. Когорты — JSON-дескрипторы,
 * резолвятся CohortMetricProbe в SQL-предикаты (kind: brand_parity | brand_ids | page_like).
 */
#[ORM\Entity(repositoryClass: MechanicExperimentRepository::class)]
#[ORM\Table(name: 'mechanic_experiment')]
#[ORM\Index(columns: ['status'], name: 'idx_mechexp_status')]
#[ORM\UniqueConstraint(name: 'uq_mechexp_code', columns: ['code'])]
class MechanicExperiment
{
    public const STATUS_PROPOSED    = 'proposed';
    public const STATUS_RUNNING     = 'running';
    public const STATUS_MEASURED    = 'measured';
    public const STATUS_ADOPTED     = 'adopted';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Стабильный ключ гипотезы из бэклога (MechanicExperimentBacklog) — идемпотентность propose. */
    #[ORM\Column(length: 64)]
    private string $code = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $hypothesis = '';

    /** Что/где меняется: route/шаблон/параметр, напр. «brand/show.html.twig — блок действий». */
    #[ORM\Column(length: 255)]
    private string $target = '';

    /** Ключ метрики: card_conversion | search_ctr | outbound_clicks | clicks | impressions. */
    #[ORM\Column(length: 32)]
    private string $metric = '';

    /** Когорта-вариант (где механика применена). JSON-дескриптор, см. CohortMetricProbe. */
    #[ORM\Column(type: Types::JSON)]
    private array $cohortA = [];

    /** Когорта-контроль (holdout / незатронутый тип страниц) — нейтрализует сезонность в DiD. */
    #[ORM\Column(type: Types::JSON)]
    private array $cohortB = [];

    #[ORM\Column(type: Types::SMALLINT)]
    private int $impact = 1;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $confidence = 1;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $ease = 1;

    /** Денормализованное произведение impact*confidence*ease. */
    #[ORM\Column]
    private int $iceScore = 1;

    /** Длина окна замера (дней): baseline = период до start, after = период до ends. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 21])]
    private int $periodDays = 21;

    #[ORM\Column(length: 16, options: ['default' => self::STATUS_PROPOSED])]
    private string $status = self::STATUS_PROPOSED;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $endsAt = null;

    /** Замеры baseline/after когорт + DiD + рекомендация (пишет evaluate). */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $resultJson = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getHypothesis(): string
    {
        return $this->hypothesis;
    }

    public function setHypothesis(string $hypothesis): static
    {
        $this->hypothesis = $hypothesis;

        return $this;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function setTarget(string $target): static
    {
        $this->target = $target;

        return $this;
    }

    public function getMetric(): string
    {
        return $this->metric;
    }

    public function setMetric(string $metric): static
    {
        $this->metric = $metric;

        return $this;
    }

    public function getCohortA(): array
    {
        return $this->cohortA;
    }

    public function setCohortA(array $cohortA): static
    {
        $this->cohortA = $cohortA;

        return $this;
    }

    public function getCohortB(): array
    {
        return $this->cohortB;
    }

    public function setCohortB(array $cohortB): static
    {
        $this->cohortB = $cohortB;

        return $this;
    }

    public function getImpact(): int
    {
        return $this->impact;
    }

    public function setImpact(int $impact): static
    {
        $this->impact = $impact;

        return $this;
    }

    public function getConfidence(): int
    {
        return $this->confidence;
    }

    public function setConfidence(int $confidence): static
    {
        $this->confidence = $confidence;

        return $this;
    }

    public function getEase(): int
    {
        return $this->ease;
    }

    public function setEase(int $ease): static
    {
        $this->ease = $ease;

        return $this;
    }

    public function getIceScore(): int
    {
        return $this->iceScore;
    }

    public function setIceScore(int $iceScore): static
    {
        $this->iceScore = $iceScore;

        return $this;
    }

    public function getPeriodDays(): int
    {
        return $this->periodDays;
    }

    public function setPeriodDays(int $periodDays): static
    {
        $this->periodDays = $periodDays;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeInterface $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getEndsAt(): ?\DateTimeInterface
    {
        return $this->endsAt;
    }

    public function setEndsAt(?\DateTimeInterface $endsAt): static
    {
        $this->endsAt = $endsAt;

        return $this;
    }

    public function getResultJson(): ?array
    {
        return $this->resultJson;
    }

    public function setResultJson(?array $resultJson): static
    {
        $this->resultJson = $resultJson;

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }
}
