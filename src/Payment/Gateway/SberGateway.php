<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

use App\Entity\PaymentProvider;
use App\Entity\SellerPaymentAccount;
use App\Service\SecretCipher;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Сбербанк интернет-эквайринг, REST: register.do + getOrderStatusExtended.do.
 * Реквизиты счёта: accountRef = userName (логин API), секрет = password.
 * Запросы form-urlencoded; суммы в копейках; валюта RUB = 643 (ISO 4217 numeric).
 *
 * ⚠️ SANDBOX-UNVERIFIED: написано по документации Сбера, без прогона на тест-контуре
 * (3dsec.sberbank.ru). Провайдер is_active=0 и не «готов» (isReadyToAcceptOnline →
 * только yookassa) — в проде не вызывается до проверки в песочнице.
 */
readonly class SberGateway implements PaymentGatewayInterface
{
    private const BASE_URL = 'https://securepayments.sberbank.ru/payment/rest/';
    private const CURRENCY_RUB = 643;

    public function __construct(
        private HttpClientInterface $http,
        private SecretCipher $cipher,
    ) {}

    public function code(): string
    {
        return PaymentProvider::CODE_SBER;
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
        $orderNumber = mb_substr(str_replace(',', '-', (string) ($metadata['order_numbers'] ?? $idempotenceKey)), 0, 32);

        $data = $this->call($account, 'register.do', [
            'orderNumber' => $orderNumber,
            'amount'      => (int) round((float) $amount * 100), // копейки
            'currency'    => self::CURRENCY_RUB,
            'returnUrl'   => $returnUrl,
            'description' => mb_substr($description, 0, 512),
        ]);

        if (isset($data['errorCode']) && (string) $data['errorCode'] !== '0') {
            throw new \RuntimeException(sprintf('Sber register: [%s] %s', $data['errorCode'], $data['errorMessage'] ?? ''));
        }

        return new PaymentInitResult((string) $data['orderId'], (string) $data['formUrl']);
    }

    public function fetchStatus(SellerPaymentAccount $account, string $gatewayPaymentId): PaymentStatusResult
    {
        $data = $this->call($account, 'getOrderStatusExtended.do', ['orderId' => $gatewayPaymentId]);

        return new PaymentStatusResult(
            $this->normalizeStatus((int) ($data['orderStatus'] ?? -1)),
            number_format(((int) ($data['amount'] ?? 0)) / 100, 2, '.', ''),
            'RUB',
        );
    }

    /** @param array<string, scalar> $params */
    private function call(SellerPaymentAccount $account, string $method, array $params): array
    {
        $base = rtrim((string) ($account->getConfig()['base_url'] ?? self::BASE_URL), '/') . '/';
        $params['userName'] = (string) $account->getAccountRef();
        $params['password'] = $this->cipher->decrypt((string) $account->getSecretEncrypted());

        return $this->http->request('POST', $base . $method, ['body' => $params])->toArray(false);
    }

    private function normalizeStatus(int $orderStatus): string
    {
        return match ($orderStatus) {
            2 => 'succeeded',          // полная авторизация
            3, 4, 6 => 'canceled',     // отменён / возврат / отклонён
            default => 'pending',      // 0 зарегистрирован, 1 преавторизация, 5 ACS
        };
    }
}
