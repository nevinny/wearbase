<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

/**
 * Нормализованный статус платежа. Значения статуса приведены к словарю YooKassa
 * ('succeeded' | 'canceled' | 'pending' | 'failed'), чтобы логика PaymentService
 * не зависела от конкретного шлюза.
 */
final readonly class PaymentStatusResult
{
    public function __construct(
        public string $status,
        public string $amountValue,
        public string $currency,
    ) {}
}
