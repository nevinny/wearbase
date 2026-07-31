<?php

namespace App\Entity;

use App\Repository\SocialPostMetricRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Снимок метрик опубликованного поста (для closed-loop app:social:evaluate).
 * KPI воронки — saves/shares/linkTaps, не лайки (см. docs/marketing_instagram.md §7).
 */
#[ORM\Entity(repositoryClass: SocialPostMetricRepository::class)]
#[ORM\Table(name: 'social_post_metric')]
#[ORM\Index(name: 'idx_spm_post', columns: ['post_id'])]
class SocialPostMetric
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?SocialPost $post = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $reach = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $saves = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $shares = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $linkTaps = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $likes = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $comments = 0;

    /** Просмотры (метрика views; у Reels отличается от reach — один зритель может смотреть повторно). */
    #[ORM\Column(options: ['default' => 0])]
    private int $views = 0;

    /** Среднее время просмотра Reels в миллисекундах (ig_reels_avg_watch_time) — показатель удержания. */
    #[ORM\Column(options: ['default' => 0])]
    private int $avgWatchMs = 0;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $measuredAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPost(): ?SocialPost
    {
        return $this->post;
    }

    public function setPost(?SocialPost $post): self
    {
        $this->post = $post;
        return $this;
    }

    public function getReach(): int
    {
        return $this->reach;
    }

    public function setReach(int $n): self
    {
        $this->reach = $n;
        return $this;
    }

    public function getSaves(): int
    {
        return $this->saves;
    }

    public function setSaves(int $n): self
    {
        $this->saves = $n;
        return $this;
    }

    public function getShares(): int
    {
        return $this->shares;
    }

    public function setShares(int $n): self
    {
        $this->shares = $n;
        return $this;
    }

    public function getLinkTaps(): int
    {
        return $this->linkTaps;
    }

    public function setLinkTaps(int $n): self
    {
        $this->linkTaps = $n;
        return $this;
    }

    public function getLikes(): int
    {
        return $this->likes;
    }

    public function setLikes(int $n): self
    {
        $this->likes = $n;
        return $this;
    }

    public function getComments(): int
    {
        return $this->comments;
    }

    public function setComments(int $n): self
    {
        $this->comments = $n;
        return $this;
    }

    public function getViews(): int
    {
        return $this->views;
    }

    public function setViews(int $n): self
    {
        $this->views = $n;
        return $this;
    }

    public function getAvgWatchMs(): int
    {
        return $this->avgWatchMs;
    }

    public function setAvgWatchMs(int $n): self
    {
        $this->avgWatchMs = $n;
        return $this;
    }

    public function getMeasuredAt(): ?\DateTimeInterface
    {
        return $this->measuredAt;
    }

    public function setMeasuredAt(?\DateTimeInterface $at): self
    {
        $this->measuredAt = $at;
        return $this;
    }

    /** Композитный «движенческий» скор для closed-loop (saves/shares весомее лайков). */
    public function engagementScore(): float
    {
        return $this->saves * 3.0 + $this->shares * 3.0 + $this->linkTaps * 2.0 + $this->comments * 1.0;
    }
}
