<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

use App\Entity\PaymentProvider;
use App\Entity\SellerPaymentAccount;
use App\Service\SecretCipher;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * CloudPayments. Реквизиты счёта: accountRef = Public ID, секрет = API Secret.
 * Редирект-ссылка создаётся через /orders/create; статус — через /payments/find
 * по InvoiceId.
 *
 * ⚠️ SANDBOX-UNVERIFIED: CloudPayments ориентирован на виджет/charge и подтверждает
 * оплату вебхуком; redirect+poll реализован по документации, без прогона. Провайдер
 * остаётся is_active=0 и не может стать «готовым» счётом — в проде код не вызывается,
 * пока интеграцию не проверят в песочнице.
 */
readonly class CloudPaymentsGateway implements PaymentGatewayInterface
{
    private const BASE_URL = 'https://api.cloudpayments.ru/';

    public function __construct(
        private HttpClientInterface $http,
        private SecretCipher $cipher,
    ) {}

    public function code(): string
    {
        return PaymentProvider::CODE_CLOUDPAYMENTS;
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
        $data = $this->request($account, 'orders/create', [
            'Amount'      => (float) $amount,
            'Currency'    => $currency,
            'Description' => $description,
            'InvoiceId'   => (string) ($metadata['order_numbers'] ?? $idempotenceKey),
            'SuccessRedirectUrl' => $returnUrl,
        ]);

        if (($data['Success'] ?? false) !== true) {
            throw new \RuntimeException('CloudPayments orders/create: ' . ($data['Message'] ?? 'error'));
        }
        $model = $data['Model'] ?? [];

        return new PaymentInitResult((string) ($model['Id'] ?? $model['Number'] ?? ''), (string) ($model['Url'] ?? ''));
    }

    public function fetchStatus(SellerPaymentAccount $account, string $gatewayPaymentId): PaymentStatusResult
    {
        // По InvoiceId находим транзакцию (статус оплаты подтверждается вебхуком).
        $data = $this->request($account, 'payments/find', ['InvoiceId' => $gatewayPaymentId]);
        $model = $data['Model'] ?? [];

        return new PaymentStatusResult(
            $this->normalizeStatus((string) ($model['Status'] ?? '')),
            number_format((float) ($model['Amount'] ?? 0), 2, '.', ''),
            (string) ($model['Currency'] ?? 'RUB'),
        );
    }

    /** @param array<string, scalar> $payload */
    private function request(SellerPaymentAccount $account, string $path, array $payload): array
    {
        $apiSecret = $this->cipher->decrypt((string) $account->getSecretEncrypted());
        $base = rtrim((string) ($account->getConfig()['base_url'] ?? self::BASE_URL), '/') . '/';

        return $this->http->request('POST', $base . $path, [
            'auth_basic' => [(string) $account->getAccountRef(), $apiSecret],
            'json'       => $payload,
        ])->toArray(false);
    }

    private function normalizeStatus(string $raw): string
    {
        return match ($raw) {
            'Completed', 'Authorized' => 'succeeded',
            'Declined', 'Cancelled'   => 'canceled',
            default => 'pending',
        };
    }
}
