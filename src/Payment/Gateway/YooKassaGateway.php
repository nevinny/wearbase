<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

use App\Entity\PaymentProvider;
use App\Entity\SellerPaymentAccount;
use App\Service\SecretCipher;
use App\Service\YooKassaClientFactory;

/**
 * Эталонная реализация — единственная проверенная в проде.
 * Деньги за заказ уходят на реквизиты счёта продавца (shopId/секрет счёта).
 */
readonly class YooKassaGateway implements PaymentGatewayInterface
{
    public function __construct(
        private YooKassaClientFactory $clientFactory,
        private SecretCipher $cipher,
    ) {}

    public function code(): string
    {
        return PaymentProvider::CODE_YOOKASSA;
    }

    public function createRedirectPayment(
        SellerPaymentAccount $account,
        string $amount,
        string $currency,
        string $description,
        string $returnUrl,
        array $metadata,
        string $idempotenceKey,
    ): PaymentInitResult {
        $response = $this->client($account)->createPayment([
            'amount'       => ['value' => $amount, 'currency' => $currency],
            'confirmation' => ['type' => 'redirect', 'return_url' => $returnUrl],
            'capture'      => true,
            'description'  => $description,
            'metadata'     => $metadata,
        ], $idempotenceKey);

        return new PaymentInitResult(
            $response->getId(),
            $response->getConfirmation()->getConfirmationUrl(),
        );
    }

    public function fetchStatus(SellerPaymentAccount $account, string $gatewayPaymentId): PaymentStatusResult
    {
        $info = $this->client($account)->getPaymentInfo($gatewayPaymentId);
        $amount = $info->getAmount();

        return new PaymentStatusResult(
            $info->getStatus(),
            $amount->getValue(),
            (string) $amount->getCurrency(),
        );
    }

    private function client(SellerPaymentAccount $account): \YooKassa\Client
    {
        $secret = $this->cipher->decrypt((string) $account->getSecretEncrypted());

        return $this->clientFactory->make((string) $account->getAccountRef(), $secret);
    }
}
