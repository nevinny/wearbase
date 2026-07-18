<?php

namespace App\Entity;

use App\Repository\AioRemediationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Аудит-лог авто-ремедиации AIO-утечки (docs/seo_sitewide_backlog.md HIGH#2,
 * docs/drmax_seo_2026_digest.md §5): по радару GSC («{бренд} чей бренд», impr≥N,
 * clicks=0) app:seo:aio-remediate теперь closed-loop — auto-apply через
 * BrandContentVersioner (grounded-описание бренда, quality-gate, окно замера
 * ведёт EvaluateExperimentsCommand). Каждая применённая строка сразу пишется
 * сюда со status=applied (KIND_DESCRIPTION) — pending/rejected/TG-кнопки
 * (aioapply:/aioreject:) убраны, ручного подтверждения больше нет.
 *
 * KIND_FAQ — legacy: старые pending-кандидаты FAQ (до перехода на closed-loop),
 * их обработчик в TelegramController удалён — они остаются в БД неприменёнными.
 */
#[ORM\Entity(repositoryClass: AioRemediationRepository::class)]
#[ORM\Table(name: 'aio_remediation')]
#[ORM\Index(name: 'idx_aio_remediation_status', columns: ['status'])]
#[ORM\Index(name: 'idx_aio_remediation_brand', columns: ['brand_id'])]
#[ORM\HasLifecycleCallbacks]
class AioRemediation
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPLIED  = 'applied';
    public const STATUS_REJECTED = 'rejected';

    /** @deprecated legacy-кандидаты Q/A до перехода на closed-loop (описание бренда). */
    public const KIND_FAQ = 'faq';
    /** Closed-loop: grounded-регенерация description бренда (app:seo:aio-remediate). */
    public const KIND_DESCRIPTION = 'description';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Brand $brand = null;

    #[ORM\Column(length: 255)]
    private string $query = '';

    /** Вид ремедиации; сейчас единственный — 'faq' (Q/A в brand_faq). */
    #[ORM\Column(length: 16, options: ['default' => self::KIND_FAQ])]
    private string $kind = self::KIND_FAQ;

    #[ORM\Column(length: 255)]
    private string $proposedQuestion = '';

    #[ORM\Column(type: 'text')]
    private string $proposedAnswer = '';

    #[ORM\Column(length: 12, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $appliedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTime();
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

    public function getQuery(): string
    {
        return $this->query;
    }

    public function setQuery(string $query): self
    {
        $this->query = $query;
        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): self
    {
        $this->kind = $kind;
        return $this;
    }

    public function getProposedQuestion(): string
    {
        return $this->proposedQuestion;
    }

    public function setProposedQuestion(string $proposedQuestion): self
    {
        $this->proposedQuestion = $proposedQuestion;
        return $this;
    }

    public function getProposedAnswer(): string
    {
        return $this->proposedAnswer;
    }

    public function setProposedAnswer(string $proposedAnswer): self
    {
        $this->proposedAnswer = $proposedAnswer;
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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getAppliedAt(): ?\DateTimeInterface
    {
        return $this->appliedAt;
    }

    public function setAppliedAt(?\DateTimeInterface $appliedAt): self
    {
        $this->appliedAt = $appliedAt;
        return $this;
    }
}
