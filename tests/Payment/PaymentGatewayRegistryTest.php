<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\SellerPaymentAccount;
use App\Payment\Gateway\PaymentGatewayInterface;
use App\Payment\Gateway\PaymentGatewayRegistry;
use App\Payment\Gateway\PaymentInitResult;
use App\Payment\Gateway\PaymentStatusResult;
use PHPUnit\Framework\TestCase;

class PaymentGatewayRegistryTest extends TestCase
{
    private function gateway(string $code): PaymentGatewayInterface
    {
        return new class($code) implements PaymentGatewayInterface {
            public function __construct(private string $code) {}
            public function code(): string { return $this->code; }
            public function createRedirectPayment(SellerPaymentAccount $a, string $amount, string $currency, string $description, string $returnUrl, array $metadata, string $key): PaymentInitResult
            {
                return new PaymentInitResult('id', 'https://pay');
            }
            public function fetchStatus(SellerPaymentAccount $a, string $id): PaymentStatusResult
            {
                return new PaymentStatusResult('succeeded', '100.00', 'RUB');
            }
        };
    }

    public function testResolvesByCode(): void
    {
        $yoo = $this->gateway('yookassa');
        $registry = new PaymentGatewayRegistry([$yoo, $this->gateway('tinkoff')]);

        $this->assertTrue($registry->has('yookassa'));
        $this->assertTrue($registry->has('tinkoff'));
        $this->assertSame($yoo, $registry->get('yookassa'));
    }

    public function testUnknownProviderThrows(): void
    {
        $registry = new PaymentGatewayRegistry([$this->gateway('yookassa')]);

        $this->assertFalse($registry->has('sbp'));
        $this->expectException(\RuntimeException::class);
        $registry->get('sbp');
    }
}
