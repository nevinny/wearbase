<?php

declare(strict_types=1);

namespace App\Service\Support;

/**
 * Домен email-адреса (lowercase, часть после @). Общий атом для сравнений
 * email↔email (BrandClaimController::checkEmailDomain) и email↔сайт
 * (App\Service\Moderation\ApplicationMatcher) — не дублируем парсинг.
 */
final class EmailDomain
{
    public static function ofEmail(string $email): string
    {
        $parts = explode('@', trim($email));

        return strtolower(trim($parts[1] ?? ''));
    }
}
