<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

/** Результат инициализации платежа: id у шлюза + URL для редиректа покупателя. */
final readonly class PaymentInitResult
{
    public function __construct(
        public string $gatewayPaymentId,
        public string $confirmationUrl,
    ) {}
}
