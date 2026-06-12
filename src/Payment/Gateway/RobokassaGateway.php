<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

use App\Entity\PaymentProvider;
use App\Entity\SellerPaymentAccount;
use App\Service\SecretCipher;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Robokassa. Инициация платежа — подписанный redirect-URL (без серверного вызова),
 * статус — OpStateExt. Нужны ДВА пароля: Пароль#1 (оплата), Пароль#2 (статус/вебхук).
 * Храним их зашифрованным JSON {"p1": "...", "p2": "..."} в secret_encrypted.
 * Реквизиты счёта: accountRef = MerchantLogin.
 *
 * ⚠️ SANDBOX-UNVERIFIED: по документации, без прогона. Провайдер is_active=0 и не «готов».
 * InvId выводится из idempotenceKey (crc32) — для прода привязать к числовому id заказа.
 * Вебхук ResultURL (ответ «OK{InvId}», подпись md5(OutSum:InvId:Пароль#2)) — отдельная задача.
 */
readonly class RobokassaGateway implements PaymentGatewayInterface
{
    private const PAY_URL = 'https://auth.robokassa.ru/Merchant/Index.aspx';
    private const STATE_URL = 'https://auth.robokassa.ru/Merchant/WebService/Service.asmx/OpStateExt';

    public function __construct(
        private HttpClientInterface $http,
        private SecretCipher $cipher,
    ) {}

    public function code(): string
    {
        return PaymentProvider::CODE_ROBOKASSA;
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
        $login = (string) $account->getAccountRef();
        [$p1] = $this->passwords($account);
        $algo = (string) ($account->getConfig()['hash_algo'] ?? 'md5');

        $outSum = number_format((float) $amount, 2, '.', '');
        $invId = (string) (crc32($idempotenceKey) & 0x7FFFFFFF);
        $signature = hash($algo, sprintf('%s:%s:%s:%s', $login, $outSum, $invId, $p1));

        $query = [
            'MerchantLogin'  => $login,
            'OutSum'         => $outSum,
            'InvId'          => $invId,
            'Description'    => mb_substr($description, 0, 100),
            'SignatureValue' => $signature,
            'Encoding'       => 'utf-8',
        ];
        if (!empty($account->getConfig()['is_test'])) {
            $query['IsTest'] = '1';
        }

        $base = (string) ($account->getConfig()['pay_url'] ?? self::PAY_URL);

        return new PaymentInitResult($invId, $base . '?' . http_build_query($query));
    }

    public function fetchStatus(SellerPaymentAccount $account, string $gatewayPaymentId): PaymentStatusResult
    {
        $login = (string) $account->getAccountRef();
        [, $p2] = $this->passwords($account);
        $algo = (string) ($account->getConfig()['hash_algo'] ?? 'md5');

        $signature = hash($algo, sprintf('%s:%s:%s', $login, $gatewayPaymentId, $p2));
        $base = (string) ($account->getConfig()['state_url'] ?? self::STATE_URL);

        $xml = $this->http->request('GET', $base, ['query' => [
            'MerchantLogin' => $login,
            'InvoiceID'     => $gatewayPaymentId,
            'Signature'     => $signature,
        ]])->getContent(false);

        $stateCode = 0;
        $sum = '0.00';
        $parsed = @simplexml_load_string($xml);
        if ($parsed !== false) {
            $stateCode = (int) ($parsed->State->Code ?? 0);
            $sum = number_format((float) ($parsed->Info->IncSum ?? 0), 2, '.', '');
        }

        return new PaymentStatusResult($this->normalizeStatus($stateCode), $sum, 'RUB');
    }

    /** @return array{0: string, 1: string} [Пароль#1, Пароль#2] */
    private function passwords(SellerPaymentAccount $account): array
    {
        $creds = json_decode($this->cipher->decrypt((string) $account->getSecretEncrypted()), true) ?: [];

        return [(string) ($creds['p1'] ?? ''), (string) ($creds['p2'] ?? '')];
    }

    private function normalizeStatus(int $stateCode): string
    {
        return match ($stateCode) {
            100 => 'succeeded',     // оплата завершена
            10, 60 => 'canceled',   // отменена / возвращена
            default => 'pending',   // 5 инициирована, 50 деньги получены от покупателя и т.п.
        };
    }
}
