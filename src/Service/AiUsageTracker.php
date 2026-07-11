<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AiUsageLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Пишет факт расхода AI-запроса (токены + $) в ai_usage_log — фундамент под
 * будущую перепродажу AI-кредитов (docs/…), сама тарификация НЕ здесь.
 * Читает usage последнего вызова из LlmService::getLastUsage(): null → no-op
 * (кеш-хит/local-ветка/ошибка запроса — реальных токенов не было).
 */
class AiUsageTracker
{
    public function __construct(
        private readonly LlmService $llm,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function record(?User $user, string $feature): void
    {
        $usage = $this->llm->getLastUsage();
        if ($usage === null) {
            return;
        }

        $log = new AiUsageLog();
        $log->setUser($user);
        $log->setFeature($feature);
        $log->setModel($usage['model']);
        $log->setPromptTokens($usage['prompt_tokens']);
        $log->setCompletionTokens($usage['completion_tokens']);
        $log->setCostUsd($usage['cost_usd']);

        $this->em->persist($log);
        $this->em->flush();
    }
}
