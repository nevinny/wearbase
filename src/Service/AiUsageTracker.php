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

    /** Локальная модель не отдаёт usage/cost, но сам факт и выбранную модель сохраняем. */
    public function recordLocal(?User $user, string $feature, string $model): void
    {
        $log = new AiUsageLog();
        $log->setUser($user);
        $log->setFeature($feature);
        $log->setModel($model);
        $log->setPromptTokens(0);
        $log->setCompletionTokens(0);
        $log->setCostUsd(0.0);

        $this->em->persist($log);
        $this->em->flush();
    }

    /**
     * Ошибка запроса (до/во время LLM-вызова, дневной cap, rate-limit) — токенов
     * не было, model неприменима. $user может быть null (пайплайн-контекст).
     *
     * Best-effort: если исходная ошибка уже угробила EM (напр. обрыв соединения БД
     * после долгого HTTP-вызова LLM), сама запись в БД бросит исключение — глотаем его,
     * иначе логирование ошибки подменяет собой graceful {ok:false} ответ вызывающего кода
     * (файловый лог wardrobe_ai пишется вызывающим кодом отдельно и не зависит от БД).
     */
    public function recordError(?User $user, string $feature, string $error): void
    {
        try {
            $log = new AiUsageLog();
            $log->setUser($user);
            $log->setFeature($feature);
            $log->setModel('n/a');
            $log->setPromptTokens(0);
            $log->setCompletionTokens(0);
            $log->setCostUsd(null);
            $log->setStatus(AiUsageLog::STATUS_ERROR);
            $log->setError(mb_substr($error, 0, 255));

            $this->em->persist($log);
            $this->em->flush();
        } catch (\Throwable) {
            // намеренно проглочено — см. docblock
        }
    }
}
