<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\DeliverExternalNotificationsCommand;
use App\Entity\ExternalNotificationOutbox;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\NotificationSettings;
use App\Notification\EmailNotifier;
use App\Notification\TelegramNotifier;
use App\Notification\WebPushPublisherInterface;
use App\Repository\ExternalNotificationOutboxRepository;
use App\Repository\NotificationSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class DeliverExternalNotificationsCommandTest extends TestCase
{
    public function testFailedDeliveryIsRetriedWithoutFailingTheWorker(): void
    {
        $user = (new User())->setEmail('parent@example.test');
        $message = new ExternalNotificationOutbox($user, Notification::CHANNEL_EMAIL, Notification::TYPE_SYSTEM, 'event:email', [
            'to' => 'parent@example.test', 'name' => 'Parent', 'subject' => 'Subject', 'html' => '<p>Body</p>',
        ]);
        $repository = $this->createMock(ExternalNotificationOutboxRepository::class);
        $calls = 0;
        $repository->expects($this->exactly(2))->method('claimNext')->willReturnCallback(function (\DateTimeImmutable $now) use ($message, &$calls): ?ExternalNotificationOutbox {
            if ($calls++ > 0) {
                return null;
            }
            $message->claim($now);
            return $message;
        });
        $settings = $this->createMock(NotificationSettingsRepository::class);
        $settings->method('findOneBy')->willReturn(null);
        $email = $this->createMock(EmailNotifier::class);
        $email->expects($this->once())->method('sendHtml')->willReturn(false);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $tester = new CommandTester(new DeliverExternalNotificationsCommand(
            $repository,
            $settings,
            $email,
            $this->createMock(TelegramNotifier::class),
            $this->createMock(WebPushPublisherInterface::class),
            $em,
        ));

        $this->assertSame(0, $tester->execute([]));
        $this->assertSame(ExternalNotificationOutbox::STATUS_PENDING, $message->getStatus());
        $this->assertSame(1, $message->getAttempts());
        $this->assertStringContainsString('1 scheduled for retry', $tester->getDisplay());
    }

    public function testPushIsCheckedAgainAndDeliveredOnlyByWorker(): void
    {
        $user = (new User())->setEmail('child@example.test');
        $message = new ExternalNotificationOutbox($user, Notification::CHANNEL_PUSH, Notification::TYPE_PURCHASE_REQUEST_DECIDED, 'decision:1:push', [
            'title' => 'Решение принято', 'body' => 'Покупка одобрена', 'url' => '/account/purchases/1',
        ]);
        $repository = $this->createMock(ExternalNotificationOutboxRepository::class);
        $calls = 0;
        $repository->method('claimNext')->willReturnCallback(function (\DateTimeImmutable $now) use ($message, &$calls): ?ExternalNotificationOutbox {
            if ($calls++ > 0) {
                return null;
            }
            $message->claim($now);
            return $message;
        });
        $settings = $this->createMock(NotificationSettingsRepository::class);
        $settings->method('findOneBy')->willReturn((new NotificationSettings())->setChannelPush(true));
        $webPush = $this->createMock(WebPushPublisherInterface::class);
        $webPush->expects(self::once())->method('send')->with($user, $message->getPayload())->willReturn(true);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $tester = new CommandTester(new DeliverExternalNotificationsCommand(
            $repository, $settings, $this->createMock(EmailNotifier::class),
            $this->createMock(TelegramNotifier::class), $webPush, $em,
        ));

        self::assertSame(0, $tester->execute([]));
        self::assertSame(ExternalNotificationOutbox::STATUS_SENT, $message->getStatus());
    }

    public function testDisabledPushPreferenceSkipsProvider(): void
    {
        $user = (new User())->setEmail('parent@example.test');
        $message = new ExternalNotificationOutbox($user, Notification::CHANNEL_PUSH, Notification::TYPE_SYSTEM, 'system:1:push', ['title' => 'Test']);
        $repository = $this->createMock(ExternalNotificationOutboxRepository::class);
        $calls = 0;
        $repository->method('claimNext')->willReturnCallback(function (\DateTimeImmutable $now) use ($message, &$calls): ?ExternalNotificationOutbox {
            if ($calls++ > 0) {
                return null;
            }
            $message->claim($now);
            return $message;
        });
        $settings = $this->createMock(NotificationSettingsRepository::class);
        $settings->method('findOneBy')->willReturn((new NotificationSettings())->setChannelPush(false));
        $webPush = $this->createMock(WebPushPublisherInterface::class);
        $webPush->expects(self::never())->method('send');

        $tester = new CommandTester(new DeliverExternalNotificationsCommand(
            $repository, $settings, $this->createMock(EmailNotifier::class),
            $this->createMock(TelegramNotifier::class), $webPush, $this->createMock(EntityManagerInterface::class),
        ));
        $tester->execute([]);

        self::assertSame(ExternalNotificationOutbox::STATUS_SENT, $message->getStatus());
    }

    public function testTransientPushFailureUsesOutboxRetry(): void
    {
        $user = (new User())->setEmail('retry@example.test');
        $message = new ExternalNotificationOutbox($user, Notification::CHANNEL_PUSH, Notification::TYPE_SYSTEM, 'system:retry:push', ['title' => 'Test']);
        $repository = $this->createMock(ExternalNotificationOutboxRepository::class);
        $calls = 0;
        $repository->method('claimNext')->willReturnCallback(function (\DateTimeImmutable $now) use ($message, &$calls): ?ExternalNotificationOutbox {
            if ($calls++ > 0) {
                return null;
            }
            $message->claim($now);
            return $message;
        });
        $settings = $this->createMock(NotificationSettingsRepository::class);
        $settings->method('findOneBy')->willReturn((new NotificationSettings())->setChannelPush(true));
        $webPush = $this->createMock(WebPushPublisherInterface::class);
        $webPush->expects(self::once())->method('send')->willReturn(false);

        $tester = new CommandTester(new DeliverExternalNotificationsCommand(
            $repository, $settings, $this->createMock(EmailNotifier::class),
            $this->createMock(TelegramNotifier::class), $webPush, $this->createMock(EntityManagerInterface::class),
        ));
        $tester->execute([]);

        self::assertSame(ExternalNotificationOutbox::STATUS_PENDING, $message->getStatus());
        self::assertSame(1, $message->getAttempts());
        self::assertStringContainsString('1 scheduled for retry', $tester->getDisplay());
    }
}
