<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Notification\TelegramNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;

class TelegramNotifierTest extends TestCase
{
    public function testSendHasShortTimeoutAndSwallowsTransportFailure(): void
    {
        $options = [];
        $client = new MockHttpClient(function (string $method, string $url, array $o) use (&$options) {
            $options = $o;
            throw new TransportException('Idle timeout reached');
        });

        $this->assertFalse((new TelegramNotifier($client, 'tok', new NullLogger()))->send('1', 'hi'));
        $this->assertLessThanOrEqual(5.0, (float) $options['max_duration']);
    }
}
