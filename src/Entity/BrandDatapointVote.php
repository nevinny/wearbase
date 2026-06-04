<?php

namespace App\Entity;

use App\Repository\BrandDatapointVoteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Голос посетителя за data-point бренда: confirm/reject (+ опционально текст
 * исправления, как «исправить неточность»). Дедуп по voter_hash =
 * sha256(ip + daily_salt + UA) — сырой IP НЕ храним (PII/152-ФЗ); повторный
 * голос с того же отпечатка = upsert. Пороги считаются по СУММЕ weight.
 */
#[ORM\Entity(repositoryClass: BrandDatapointVoteRepository::class)]
#[ORM\Table(name: 'brand_datapoint_vote')]
#[ORM\UniqueConstraint(name: 'uniq_vote', columns: ['datapoint_id', 'voter_hash'])]
#[ORM\Index(name: 'idx_vote_dp', columns: ['datapoint_id'])]
class BrandDatapointVote
{
    public const VOTE_CONFIRM = 'confirm';
    public const VOTE_REJECT  = 'reject';

    public const WEIGHT_ANON = 1;
    public const WEIGHT_USER = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?BrandDatapoint $datapoint = null;

    #[ORM\Column(length: 8)]
    private string $vote = self::VOTE_CONFIRM;

    /** Предложенное исправление («исправить неточность»); публично НЕ рендерится. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $suggestion = null;

    #[ORM\Column(length: 64)]
    private string $voterHash = '';

    /** id фронтового User, если залогинен (вес выше). */
    #[ORM\Column(nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(type: 'smallint', options: ['default' => self::WEIGHT_ANON])]
    private int $weight = self::WEIGHT_ANON;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDatapoint(): ?BrandDatapoint
    {
        return $this->datapoint;
    }

    public function setDatapoint(?BrandDatapoint $dp): self
    {
        $this->datapoint = $dp;
        return $this;
    }

    public function getVote(): string
    {
        return $this->vote;
    }

    public function setVote(string $vote): self
    {
        $this->vote = $vote;
        return $this;
    }

    public function getSuggestion(): ?string
    {
        return $this->suggestion;
    }

    public function setSuggestion(?string $suggestion): self
    {
        $this->suggestion = $suggestion;
        return $this;
    }

    public function getVoterHash(): string
    {
        return $this->voterHash;
    }

    public function setVoterHash(string $hash): self
    {
        $this->voterHash = $hash;
        return $this;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function setUserId(?int $id): self
    {
        $this->userId = $id;
        return $this;
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function setWeight(int $weight): self
    {
        $this->weight = $weight;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
