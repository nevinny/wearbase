<?php

declare(strict_types=1);


namespace App\Service\News;

/**
 * Вежливость к источникам: не чаще одного запроса в секунду на хост
 * (политика конвейера, _docs/news-sources.md §3). Интервал инъектится —
 * в тестах ставим 0.
 */
final class HostPoliteness
{
    /** @var array<string, float> host → timestamp последнего запроса */
    private array $lastRequest = [];

    public function __construct(private readonly float $minIntervalSeconds = 1.0)
    {
    }

    /** Блокирует до истечения интервала и фиксирует новый запрос. */
    public function guard(string $host): void
    {
        if ($host === '') {
            return;
        }

        $now = microtime(true);
        $last = $this->lastRequest[$host] ?? null;
        if ($last !== null) {
            $wait = $this->minIntervalSeconds - ($now - $last);
            if ($wait > 0) {
                usleep((int) ceil($wait * 1_000_000));
            }
        }
        $this->lastRequest[$host] = microtime(true);
    }
}
