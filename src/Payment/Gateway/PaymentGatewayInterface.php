<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

use App\Entity\SellerPaymentAccount;

/**
 * Платёжный шлюз приёма оплаты заказов на реквизиты продавца.
 * Каждый провайдер (yookassa, tinkoff, cloudpayments) — отдельная реализация.
 */
interface PaymentGatewayInterface
{
    /** Код провайдера из PaymentProvider::CODE_* — по нему резолвится в реестре. */
    public function code(): string;

    /**
     * Создаёт платёж с редиректом на форму оплаты.
     *
     * @param array<string, scalar|null> $metadata
     */
    public function createRedirectPayment(
        SellerPaymentAccount $account,
        string $amount,
        string $currency,
        string $description,
        string $returnUrl,
        array $metadata,
        string $idempotenceKey,
    ): PaymentInitResult;

    /** Авторитетный статус платежа у шлюза (для подтверждения через вебхук). */
    public function fetchStatus(SellerPaymentAccount $account, string $gatewayPaymentId): PaymentStatusResult;
}
