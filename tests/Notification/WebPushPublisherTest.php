<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Entity\User;
use App\Notification\WebPushPublisher;
use App\Repository\WebPushSubscriptionRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

final class WebPushPublisherTest extends TestCase
{
    public function testUnconfiguredProviderDoesNotLoadOrSendSubscriptions(): void
    {
        $user = (new User())->setEmail('push-disabled@test.local');
        $subscriptions = $this->createMock(WebPushSubscriptionRepository::class);
        $subscriptions->expects(self::never())->method('findActiveForUser');

        $result = (new WebPushPublisher($subscriptions, new NullLogger(), '', '', 'mailto:test@example.com'))->send($user, ['title' => 'Test']);

        self::assertTrue($result);
    }

    public function testProviderFailureDoesNotLogEndpointOrBrowserKeys(): void
    {
        $user = (new User())->setEmail('push-log@test.local');
        $subscriptions = $this->createMock(WebPushSubscriptionRepository::class);
        $subscriptions->method('findActiveForUser')->willReturn([
            new \App\Entity\WebPushSubscription($user, 'https://push.example/endpoint-secret', 'browser-public-secret', 'browser-auth-secret', 'aes128gcm'),
        ]);
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        $result = (new WebPushPublisher($subscriptions, $logger, 'invalid-public', 'invalid-private', 'mailto:test@example.com'))->send($user, ['title' => 'Test']);

        self::assertFalse($result);
        $records = json_encode($handler->getRecords(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('endpoint-secret', $records);
        self::assertStringNotContainsString('browser-public-secret', $records);
        self::assertStringNotContainsString('browser-auth-secret', $records);
    }
}
