<?php

declare(strict_types=1);

namespace App\Payment\Gateway;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Реестр платёжных шлюзов: резолвит реализацию по коду провайдера.
 * Незарегистрированный код → исключение (так неподдержанный в рантайме
 * провайдер физически не может принять деньги).
 */
class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $byCode = [];

    /**
     * @param iterable<PaymentGatewayInterface> $gateways
     */
    public function __construct(
        #[TaggedIterator('app.payment_gateway')] iterable $gateways,
    ) {
        foreach ($gateways as $gateway) {
            $this->byCode[$gateway->code()] = $gateway;
        }
    }

    public function has(string $code): bool
    {
        return isset($this->byCode[$code]);
    }

    public function get(string $code): PaymentGatewayInterface
    {
        return $this->byCode[$code]
            ?? throw new \RuntimeException(sprintf('Платёжный провайдер «%s» не поддерживается в рантайме.', $code));
    }
}
