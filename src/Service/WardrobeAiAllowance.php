<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ReferralRewardGrant;
use App\Entity\User;
use App\Repository\AiDailyUsageRepository;
use App\Repository\ReferralRewardGrantRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Эффективный дневной лимит AI-подсказок гардероба (спец «Реферальная программа» §6):
 * база 30/день + сумма активных реферальных грантов, но суммарный бамп ≤+30 —
 * эффективный лимит ≤60 даже при 20 друзьях. Клонирует паттерн WardrobeAiMeter
 * (api_usage_daily), только per-user: атомарный условный инкремент ai_daily_usage.
 *
 * Config-лимитер rate_limiter.yaml (wardrobe_ai) остаётся антиспам-полом и здесь
 * не дублируется; тексты ошибок в контроллерах не меняются.
 */
final class WardrobeAiAllowance
{
    /** База без грантов — совпадает с config/packages/rate_limiter.yaml (wardrobe_ai). */
    public const BASE_DAILY_LIMIT = 30;

    private const TABLE = 'ai_daily_usage';

    public function __construct(
        private readonly Connection $db,
        private readonly ReferralRewardGrantRepository $grants,
    ) {}

    /** Эффективный лимит пользователя на сегодня: 30 + Σ активных грантов с потолком ≤+30. */
    public function effectiveLimit(User $user): int
    {
        $bump = min($this->grants->sumActiveDailyBump($user), ReferralRewardGrant::DAILY_BUMP_CEILING);

        return self::BASE_DAILY_LIMIT + $bump;
    }

    public function usedToday(User $user): int
    {
        return (int) $this->db->fetchOne(
            sprintf('SELECT requests FROM %s WHERE user_id = :u AND usage_date = :d', self::TABLE),
            ['u' => $user->getId(), 'd' => $this->today()],
        );
    }

    public function remainingToday(User $user): int
    {
        return max(0, $this->effectiveLimit($user) - $this->usedToday($user));
    }

    /**
     * Списать один запрос, если дневной бюджет позволяет. Атомарно: условный
     * UPDATE инкрементирует только при requests < limit; отсутствующую строку
     * создаёт INSERT (гонка двух вставок консервативно отклоняет вторую).
     */
    public function consume(User $user): bool
    {
        $limit = $this->effectiveLimit($user);

        $affected = $this->db->executeStatement(
            sprintf('UPDATE %s SET requests = requests + 1 WHERE user_id = :u AND usage_date = :d AND requests < :l', self::TABLE),
            ['u' => $user->getId(), 'd' => $this->today(), 'l' => $limit],
        );
        if ($affected === 1) {
            return true;
        }

        try {
            $this->db->insert(self::TABLE, [
                'user_id' => (int) $user->getId(),
                'usage_date' => $this->today(),
                'requests' => 1,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false; // строку создал конкурент — его инкремент уже учтён выше
        }

        return true;
    }

    private function today(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }
}
