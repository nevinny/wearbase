<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;

final class ServiceWorkerPushSafetyTest extends TestCase
{
    public function testNotificationClickIsRestrictedToSameOriginAccountUrls(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/public_html/service-worker.js');
        self::assertIsString($source);
        self::assertStringContainsString("url.origin === self.location.origin", $source);
        self::assertStringContainsString("/^\\/account(?:\\/|$)/", $source);
        self::assertStringContainsString("'/account/notifications'", $source);
        self::assertStringNotContainsString('openWindow(event.notification.data.url)', $source);
    }
}
