<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Notification;
use App\Entity\NotificationSettings;
use App\Entity\User;
use App\Notification\EmailNotifier;
use App\Notification\NotificationDispatcher;
use App\Notification\TelegramNotifier;
use App\Repository\NotificationSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class NotificationDispatcherTest extends TestCase
{
    private User $recipient;
    private EntityManagerInterface $em;
    private EmailNotifier $emailNotifier;
    private TelegramNotifier $telegramNotifier;
    private NotificationSettingsRepository $settingsRepo;

    protected function setUp(): void
    {
        $this->recipient = new User();
        $this->recipient->setEmail('manager@test.local');
        $this->recipient->setRoles(['ROLE_BRAND_MANAGER']);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->emailNotifier = $this->createMock(EmailNotifier::class);
        $this->telegramNotifier = $this->createMock(TelegramNotifier::class);
        $this->settingsRepo = $this->createMock(NotificationSettingsRepository::class);
    }

    private function createDispatcher(): NotificationDispatcher
    {
        return new NotificationDispatcher(
            $this->em,
            $this->emailNotifier,
            $this->telegramNotifier,
            $this->settingsRepo,
        );
    }

    public function testDispatchPersistsInAppNotification(): void
    {
        $this->settingsRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->once())->method('persist')
            ->with($this->callback(fn(Notification $n) =>
                $n->getRecipient() === $this->recipient
                && $n->getType() === Notification::TYPE_ORDER_NEW
                && $n->getTitle() === 'Новый заказ #WB-2026-00001'
            ));
        // Диспетчер только persist'ит in-app — flush контролирует вызывающий (INC-18)

        $this->createDispatcher()->dispatch(
            $this->recipient,
            Notification::TYPE_ORDER_NEW,
            'Новый заказ #WB-2026-00001',
            'Поступил заказ на сумму 5490.00 руб.',
            ['order_id' => 1, 'order_number' => 'WB-2026-00001'],
        );
    }

    public function testDispatchDoesNotSendEmailWhenNoEmailTemplate(): void
    {
        $this->settingsRepo->method('findOneBy')->willReturn(null);

        $this->emailNotifier->expects($this->never())->method('send');

        $this->createDispatcher()->dispatch(
            $this->recipient,
            Notification::TYPE_ORDER_NEW,
            'Test title',
        );

        $this->assertTrue(true);
    }

    public function testDispatchSendsEmailWhenTemplateProvided(): void
    {
        $this->settingsRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $this->emailNotifier->expects($this->once())->method('send')
            ->with($this->recipient, 'Test email', 'new_order_brand', ['order' => 'stub']);

        $this->createDispatcher()->dispatch(
            $this->recipient,
            Notification::TYPE_ORDER_NEW,
            'Test email',
            null,
            null,
            'new_order_brand',
            ['order' => 'stub'],
        );
    }

    public function testDispatchRespectsSettingsInappDisabled(): void
    {
        $settings = new NotificationSettings();
        $settings->setChannelInapp(false);
        $settings->setChannelEmail(false);
        $settings->setChannelTelegram(false);

        $this->settingsRepo->method('findOneBy')->willReturn($settings);
        $this->em->expects($this->never())->method('persist');

        $this->createDispatcher()->dispatch(
            $this->recipient,
            Notification::TYPE_ORDER_NEW,
            'Should not be created',
        );
    }

    public function testDispatchSendsTelegramWhenEnabled(): void
    {
        $this->recipient->setTelegramChatId('12345');

        $settings = new NotificationSettings();
        $settings->setChannelInapp(false);
        $settings->setChannelEmail(false);
        $settings->setChannelTelegram(true);

        $this->settingsRepo->method('findOneBy')->willReturn($settings);

        $this->telegramNotifier->expects($this->once())->method('send')
            ->with('12345', $this->stringContains('Test telegram'));

        $this->createDispatcher()->dispatch(
            $this->recipient,
            Notification::TYPE_ORDER_NEW,
            'Test telegram',
        );
    }

    public function testDispatchSkipsTelegramWhenNoChatId(): void
    {
        // User has no telegramChatId
        $settings = new NotificationSettings();
        $settings->setChannelTelegram(true);

        $this->settingsRepo->method('findOneBy')->willReturn($settings);

        $this->telegramNotifier->expects($this->never())->method('send');

        $this->createDispatcher()->dispatch(
            $this->recipient,
            Notification::TYPE_ORDER_NEW,
            'Test no telegram',
        );
    }
}
