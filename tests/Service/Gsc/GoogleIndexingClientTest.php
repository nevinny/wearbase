<?php

declare(strict_types=1);

namespace App\Tests\Service\Gsc;

use App\Service\Gsc\GoogleIndexingClient;
use PHPUnit\Framework\TestCase;

/**
 * 403 у Indexing API — это и квота, и отказ в доступе. С 2026-07-04 все пинги получали
 * 403 ACCESS_TOKEN_SCOPE_INSUFFICIENT, а команда писала «квота исчерпана» — различаем.
 */
final class GoogleIndexingClientTest extends TestCase
{
    public function testScopeErrorIsNotQuota(): void
    {
        self::assertFalse(GoogleIndexingClient::isQuotaReason(403, 'ACCESS_TOKEN_SCOPE_INSUFFICIENT'));
        self::assertFalse(GoogleIndexingClient::isQuotaReason(403, 'PERMISSION_DENIED'));
        self::assertFalse(GoogleIndexingClient::isQuotaReason(403, null));
    }

    public function testQuotaReasons(): void
    {
        self::assertTrue(GoogleIndexingClient::isQuotaReason(429, null));
        self::assertTrue(GoogleIndexingClient::isQuotaReason(403, 'RATE_LIMIT_EXCEEDED'));
        self::assertTrue(GoogleIndexingClient::isQuotaReason(403, 'RESOURCE_EXHAUSTED'));
    }
}
