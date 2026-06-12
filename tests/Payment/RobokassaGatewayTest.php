<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\SellerPaymentAccount;
use App\Payment\Gateway\RobokassaGateway;
use App\Service\SecretCipher;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RobokassaGatewayTest extends TestCase
{
    public function testRedirectUrlCarriesCorrectSignature(): void
    {
        $cipher = new SecretCipher(base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

        $account = new SellerPaymentAccount();
        $account->setAccountRef('demo');
        $account->setSecretEncrypted($cipher->encrypt(json_encode(['p1' => 'pass1', 'p2' => 'pass2'])));

        $gateway = new RobokassaGateway($this->createMock(HttpClientInterface::class), $cipher);

        $result = $gateway->createRedirectPayment(
            $account, '100.00', 'RUB', 'Order', 'https://ret', ['order_numbers' => 'A1'], 'orders-A1',
        );

        $invId = (string) (crc32('orders-A1') & 0x7FFFFFFF);
        $expectedSig = md5("demo:100.00:{$invId}:pass1");

        $this->assertSame($invId, $result->gatewayPaymentId);
        $this->assertStringContainsString('SignatureValue=' . $expectedSig, $result->confirmationUrl);
        $this->assertStringContainsString('OutSum=100.00', $result->confirmationUrl);
        $this->assertStringContainsString('MerchantLogin=demo', $result->confirmationUrl);
    }
}
