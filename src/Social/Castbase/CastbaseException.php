<?php

declare(strict_types=1);

namespace App\Social\Castbase;

/** Ошибка API castbase. status 0 = сеть/таймаут; retryable = можно повторить с тем же Idempotency-Key. */
final class CastbaseException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $errorCode = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
