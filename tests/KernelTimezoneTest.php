<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Веб (php-fpm) и консоль ходят в одну БД: если у них разные часовые пояса,
 * записанное вебом «сейчас» для консоли лежит на 3 часа в будущем. Так письма
 * из external_notification_outbox ждали доставки лишние 3 часа.
 */
class KernelTimezoneTest extends KernelTestCase
{
    public function testAppPinsMoscowTimezoneRegardlessOfPhpIni(): void
    {
        date_default_timezone_set('UTC');

        self::bootKernel();

        $this->assertSame('Europe/Moscow', date_default_timezone_get());
    }
}
