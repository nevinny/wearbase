<?php

declare(strict_types=1);

namespace App\Social\Castbase;

/**
 * Ошибка API castbase. status 0 = сеть/таймаут; retryable = можно повторить с тем же Idempotency-Key.
 * errorCode — верхний `code` problem+json (validation_failed, external_ref_taken…); причины по полям
 * (unsupported_option, channel_not_active…) castbase кладёт в `errors[]` — они в $fieldErrors.
 */
final class CastbaseException extends \RuntimeException
{
    /**
     * @param list<array{field: string, code: string, message: string}> $fieldErrors
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $errorCode = null,
        public readonly bool $retryable = false,
        public readonly array $fieldErrors = [],
    ) {
        parent::__construct($message);
    }

    /** Есть ли в errors[] запись с этим кодом (errors[].code). */
    public function hasFieldError(string $code): bool
    {
        foreach ($this->fieldErrors as $error) {
            if ($error['code'] === $code) {
                return true;
            }
        }

        return false;
    }
}
