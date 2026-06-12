<?php

declare(strict_types=1);

namespace App\Service;

use YooKassa\Client;

/**
 * Создаёт YooKassa\Client под конкретные реквизиты — платформенные (подписки)
 * или счёта бренда (заказы). Один шлюз на запрос, без общего состояния.
 */
readonly class YooKassaClientFactory
{
    public function make(string $shopId, string $secretKey): Client
    {
        $client = new Client();
        $client->setAuth($shopId, $secretKey);

        return $client;
    }
}
