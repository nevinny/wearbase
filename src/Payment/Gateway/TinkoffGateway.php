<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

use App\Entity\PaymentProvider;
use App\Entity\SellerPaymentAccount;
use App\Service\SecretCipher;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Т-Бизнес (Тинькофф Эквайринг), Merchant API v2: Init + GetState.
 * Реквизиты счёта: accountRef = TerminalKey, секрет = Password.
 *
 * ⚠️ SANDBOX-UNVERIFIED: написано по документации, без прогона на боевом/тестовом
 * терминале. Провайдер остаётся is_active=0; счёт «готовым» (isReadyToAcceptOnline)
 * становится только yookassa — этот код не может принять деньги в проде, пока
 * готовность/активацию не пересмотрят после песочницы.
 */
readonly class TinkoffGateway implements PaymentGatewayInterface
{
    private const BASE_URL = 'https://securepay.tinkoff.ru/v2/';

    public function __construct(
        private HttpClientInterface $http,
        private SecretCipher $cipher,
    ) {}

    public function code(): string
    {
        return PaymentProvider::CODE_TINKOFF;
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
        $password = $this->cipher->decrypt((string) $account->getSecretEncrypted());
        $params = [
            'TerminalKey' => (string) $account->getAccountRef(),
            'Amount'      => (int) round((float) $amount * 100), // в копейках
            'OrderId'     => (string) ($metadata['order_numbers'] ?? $idempotenceKey),
            'Description' => mb_substr($description, 0, 250),
            'SuccessURL'  => $returnUrl,
        ];
        $params['Token'] = $this->sign($params, $password);

        $data = $this->http->request('POST', self::base($account) . 'Init', ['json' => $params])->toArray(false);
        if (($data['Success'] ?? false) !== true) {
            throw new \RuntimeException(sprintf('Tinkoff Init: %s %s', $data['Message'] ?? 'error', $data['Details'] ?? ''));
        }

        return new PaymentInitResult((string) $data['PaymentId'], (string) $data['PaymentURL']);
    }

    public function fetchStatus(SellerPaymentAccount $account, string $gatewayPaymentId): PaymentStatusResult
    {
        $password = $this->cipher->decrypt((string) $account->getSecretEncrypted());
        $params = [
            'TerminalKey' => (string) $account->getAccountRef(),
            'PaymentId'   => $gatewayPaymentId,
        ];
        $params['Token'] = $this->sign($params, $password);

        $data = $this->http->request('POST', self::base($account) . 'GetState', ['json' => $params])->toArray(false);

        return new PaymentStatusResult(
            $this->normalizeStatus((string) ($data['Status'] ?? '')),
            number_format(((int) ($data['Amount'] ?? 0)) / 100, 2, '.', ''),
            'RUB',
        );
    }

    /** Токен Тинькофф: sha256 от значений корневых скалярных параметров + Password, по алфавиту ключей. */
    private function sign(array $params, string $password): string
    {
        unset($params['Token']);
        $params['Password'] = $password;
        $flat = array_filter($params, static fn ($v) => !is_array($v));
        ksort($flat);
        $concat = implode('', array_map(
            static fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v,
            $flat,
        ));

        return hash('sha256', $concat);
    }

    private function normalizeStatus(string $raw): string
    {
        return match ($raw) {
            'CONFIRMED', 'AUTHORIZED' => 'succeeded',
            'REJECTED', 'CANCELED', 'DEADLINE_EXPIRED' => 'canceled',
            default => 'pending',
        };
    }

    /** Базовый URL можно переопределить через config счёта (песочница). */
    private static function base(SellerPaymentAccount $account): string
    {
        $base = $account->getConfig()['base_url'] ?? self::BASE_URL;

        return rtrim((string) $base, '/') . '/';
    }
}
