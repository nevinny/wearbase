<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

use App\Entity\PaymentProvider;
use App\Entity\SellerPaymentAccount;
use App\Service\SecretCipher;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * PayKeeper, JSON API на персональном поддомене мерчанта
 * (https://{merchant}.server.paykeeper.ru — задаётся в config.base_url).
 * Реквизиты счёта: accountRef = login (Basic auth), секрет = password.
 *
 * Флоу: GET /info/settings/token/ → token; POST /change/invoice/preview/ (token) → invoice_id;
 * редирект на /bill/{invoice_id}/. Статус: GET /info/invoice/byid/?id= (created|sent|paid|expired).
 *
 * ⚠️ SANDBOX-UNVERIFIED: по доке docs.paykeeper.ru, без прогона. Провайдер is_active=0,
 * не «готов» — в проде не вызывается. base_url обязателен (поддомен мерчанта).
 */
readonly class PaykeeperGateway implements PaymentGatewayInterface
{
    public function __construct(
        private HttpClientInterface $http,
        private SecretCipher $cipher,
    ) {}

    public function code(): string
    {
        return PaymentProvider::CODE_PAYKEEPER;
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
        $base = $this->base($account);

        $data = $this->http->request('POST', $base . '/change/invoice/preview/', [
            'auth_basic' => $this->basic($account),
            'body'       => [
                'pay_amount'   => number_format((float) $amount, 2, '.', ''),
                'orderid'      => (string) ($metadata['order_numbers'] ?? $idempotenceKey),
                'service_name' => mb_substr($description, 0, 250),
                'token'        => $this->token($account),
            ],
        ])->toArray(false);

        $invoiceId = (string) ($data['invoice_id'] ?? '');
        if ($invoiceId === '') {
            throw new \RuntimeException('PayKeeper: invoice_id не получен (' . ($data['msg'] ?? 'unknown') . ')');
        }

        return new PaymentInitResult($invoiceId, $base . '/bill/' . $invoiceId . '/');
    }

    public function fetchStatus(SellerPaymentAccount $account, string $gatewayPaymentId): PaymentStatusResult
    {
        $data = $this->http->request('GET', $this->base($account) . '/info/invoice/byid/', [
            'auth_basic' => $this->basic($account),
            'query'      => ['id' => $gatewayPaymentId],
        ])->toArray(false);
        $invoice = $data['invoice'][0] ?? $data;

        return new PaymentStatusResult(
            $this->normalizeStatus((string) ($invoice['status'] ?? '')),
            number_format((float) ($invoice['pay_amount'] ?? 0), 2, '.', ''),
            'RUB',
        );
    }

    /** Одноразовый токен для подписи POST-запросов. */
    private function token(SellerPaymentAccount $account): string
    {
        $data = $this->http->request('GET', $this->base($account) . '/info/settings/token/', [
            'auth_basic' => $this->basic($account),
        ])->toArray(false);

        return (string) ($data['token'] ?? '');
    }

    /** @return array{0: string, 1: string} */
    private function basic(SellerPaymentAccount $account): array
    {
        return [(string) $account->getAccountRef(), $this->cipher->decrypt((string) $account->getSecretEncrypted())];
    }

    private function base(SellerPaymentAccount $account): string
    {
        $base = (string) ($account->getConfig()['base_url'] ?? '');
        if ($base === '') {
            throw new \RuntimeException('PayKeeper: не задан base_url (поддомен мерчанта) в config счёта');
        }

        return rtrim($base, '/');
    }

    private function normalizeStatus(string $raw): string
    {
        return match ($raw) {
            'paid'    => 'succeeded',
            'expired' => 'canceled',
            default   => 'pending', // created | sent
        };
    }
}
