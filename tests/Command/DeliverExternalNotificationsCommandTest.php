<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\DeliverExternalNotificationsCommand;
use App\Entity\ExternalNotificationOutbox;
use App\Entity\Notification;
use App\Entity\User;
use App\Notification\EmailNotifier;
use App\Notification\TelegramNotifier;
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
            $em,
        ));

        $this->assertSame(0, $tester->execute([]));
        $this->assertSame(ExternalNotificationOutbox::STATUS_PENDING, $message->getStatus());
        $this->assertSame(1, $message->getAttempts());
        $this->assertStringContainsString('1 scheduled for retry', $tester->getDisplay());
    }
}
