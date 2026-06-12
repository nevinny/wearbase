<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

use App\Entity\PaymentProvider;
use App\Entity\SellerPaymentAccount;
use App\Service\SecretCipher;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Payselection (hosted payment page), REST gw.payselection.com.
 * Реквизиты счёта: accountRef = SiteId, секрет = SecretKey.
 * Подпись запроса — HMAC-SHA256 по канонической строке "METHOD\nPATH\nSITE_ID\nREQUEST_ID\nBODY"
 * в заголовке X-REQUEST-SIGNATURE.
 *
 * ⚠️ SANDBOX-UNVERIFIED + СХЕМУ ПОДПИСИ/ПОЛЯ ОТВЕТА СВЕРИТЬ С АКТУАЛЬНОЙ ДОКОЙ И SDK
 * (Payselection/Payselection-PayApp-SDK-PHP) перед активацией. Провайдер is_active=0,
 * не «готов» (isReadyToAcceptOnline → только yookassa) — в проде не вызывается.
 */
readonly class PayselectionGateway implements PaymentGatewayInterface
{
    private const API_URL = 'https://gw.payselection.com';

    public function __construct(
        private HttpClientInterface $http,
        private SecretCipher $cipher,
    ) {}

    public function code(): string
    {
        return PaymentProvider::CODE_PAYSELECTION;
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
        $path = '/payments/requests/public';
        $body = json_encode([
            'MetaData'       => ['PaymentType' => 'Pay'],
            'PaymentRequest' => [
                'OrderId'     => (string) ($metadata['order_numbers'] ?? $idempotenceKey),
                'Amount'      => number_format((float) $amount, 2, '.', ''),
                'Currency'    => $currency,
                'Description' => mb_substr($description, 0, 250),
                'ReturnUrl'   => $returnUrl,
            ],
        ], JSON_THROW_ON_ERROR);

        $data = $this->signedRequest($account, 'POST', $path, $body);

        return new PaymentInitResult(
            (string) ($data['TransactionId'] ?? $data['Id'] ?? ''),
            (string) ($data['RedirectUrl'] ?? $data['Url'] ?? ''),
        );
    }

    public function fetchStatus(SellerPaymentAccount $account, string $gatewayPaymentId): PaymentStatusResult
    {
        $path = '/transactions/' . rawurlencode($gatewayPaymentId);
        $data = $this->signedRequest($account, 'GET', $path, '');
        $tx = $data['TransactionState'] ?? $data;

        return new PaymentStatusResult(
            $this->normalizeStatus((string) ($tx['Status'] ?? '')),
            number_format((float) ($tx['Amount'] ?? 0), 2, '.', ''),
            (string) ($tx['Currency'] ?? 'RUB'),
        );
    }

    /** @return array<string, mixed> */
    private function signedRequest(SellerPaymentAccount $account, string $method, string $path, string $body): array
    {
        $siteId = (string) $account->getAccountRef();
        $secretKey = $this->cipher->decrypt((string) $account->getSecretEncrypted());
        $requestId = bin2hex(random_bytes(16));

        $canonical = implode("\n", [$method, $path, $siteId, $requestId, $body]);
        $signature = base64_encode(hash_hmac('sha256', $canonical, $secretKey, true));

        $options = [
            'headers' => [
                'X-SITE-ID'          => $siteId,
                'X-REQUEST-ID'       => $requestId,
                'X-REQUEST-SIGNATURE'=> $signature,
                'Content-Type'       => 'application/json',
            ],
        ];
        if ($body !== '') {
            $options['body'] = $body;
        }

        $base = rtrim((string) ($account->getConfig()['base_url'] ?? self::API_URL), '/');

        return $this->http->request($method, $base . $path, $options)->toArray(false);
    }

    private function normalizeStatus(string $raw): string
    {
        return match ($raw) {
            'Charged', 'Authorized', 'Completed' => 'succeeded',
            'Declined', 'Cancelled', 'Voided'    => 'canceled',
            default => 'pending',
        };
    }
}
