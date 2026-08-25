<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-user дневной счётчик обращений к AI-ассисту гардероба — таблица
 * ai_daily_usage под атомарный upsert WardrobeAiAllowance (клон паттерна
 * api_usage_daily из WardrobeAiMeter, но на пользователя).
 */
#[ORM\Entity(repositoryClass: \App\Repository\AiDailyUsageRepository::class)]
#[ORM\Table(name: 'ai_daily_usage')]
#[ORM\UniqueConstraint(name: 'uniq_ai_user_day', columns: ['user_id', 'usage_date'])]
class AiDailyUsage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Без FK: счётчик живёт своей жизнью и не должен мешать удалению профиля. */
    #[ORM\Column]
    private int $userId;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $usageDate;

    #[ORM\Column(options: ['default' => 0])]
    private int $requests = 0;

    public function __construct(int $userId, \DateTimeImmutable $usageDate, int $requests = 0)
    {
        $this->userId = $userId;
        $this->usageDate = $usageDate;
        $this->requests = $requests;
    }

    public function getId(): ?int { return $this->id; }
    public function getUserId(): int { return $this->userId; }
    public function getUsageDate(): \DateTimeImmutable { return $this->usageDate; }
    public function getRequests(): int { return $this->requests; }
}
