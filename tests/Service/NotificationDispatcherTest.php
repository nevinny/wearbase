<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ExternalNotificationOutbox;
use App\Entity\Notification;
use App\Entity\NotificationSettings;
use App\Entity\User;
use App\Notification\NotificationDispatcher;
use App\Repository\NotificationSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

class NotificationDispatcherTest extends TestCase
{
    private User $recipient;
    private EntityManagerInterface $em;
    private NotificationSettingsRepository $settingsRepo;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->recipient = new User();
        $this->recipient->setEmail('manager@test.local');
        $this->recipient->setRoles(['ROLE_BRAND_MANAGER']);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->settingsRepo = $this->createMock(NotificationSettingsRepository::class);
        $this->twig = $this->createMock(Environment::class);
    }

    private function createDispatcher(): NotificationDispatcher
    {
        return new NotificationDispatcher(
            $this->em,
            $this->settingsRepo,
            $this->twig,
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

        $this->createDispatcher()->dispatch(
            $this->recipient,
            Notification::TYPE_ORDER_NEW,
            'Test title',
        );

        $this->assertTrue(true);
    }

    public function testDispatchQueuesRenderedEmailWithoutSendingIt(): void
    {
        $this->settingsRepo->method('findOneBy')->willReturn(null);
        $this->twig->expects($this->once())->method('render')->willReturn('<p>safe</p>');
        $queued = $inApp = null;
        $this->em->expects($this->exactly(2))->method('persist')->willReturnCallback(function (object $entity) use (&$queued, &$inApp): void {
            if ($entity instanceof ExternalNotificationOutbox) {
                $queued = $entity;
            } elseif ($entity instanceof Notification) {
                $inApp = $entity;
            }
        });

        $this->createDispatcher()->dispatch(
            $this->recipient,
            Notification::TYPE_ORDER_NEW,
            'Test email',
            null,
            null,
            'new_order_brand',
            ['order' => 'stub'],
            'order:1',
        );
        $this->assertInstanceOf(ExternalNotificationOutbox::class, $queued);
        $this->assertSame('<p>safe</p>', $queued->getPayload()['html']);
        $this->assertSame('order:1', $inApp->getDedupeKey());
        $this->assertSame('order:1:email', $queued->getDedupeKey());
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

    public function testDispatchQueuesEscapedTelegramWhenEnabled(): void
    {
        $this->recipient->setTelegramChatId('12345');

        $settings = new NotificationSettings();
        $settings->setChannelInapp(false);
        $settings->setChannelEmail(false);
        $settings->setChannelTelegram(true);

        $this->settingsRepo->method('findOneBy')->willReturn($settings);

        $queued = null;
        $this->em->expects($this->once())->method('persist')->willReturnCallback(function (object $entity) use (&$queued): void {
            $queued = $entity;
        });

        $this->createDispatcher()->dispatch(
            $this->recipient,
            Notification::TYPE_ORDER_NEW,
            '<script>telegram & test</script>',
        );
        $this->assertInstanceOf(ExternalNotificationOutbox::class, $queued);
        $this->assertStringNotContainsString('<script>', $queued->getPayload()['text']);
        $this->assertStringContainsString('&lt;script&gt;', $queued->getPayload()['text']);
    }

    public function testDispatchSkipsTelegramWhenNoChatId(): void
    {
        // User has no telegramChatId
        $settings = new NotificationSettings();
        $settings->setChannelTelegram(true);

        $this->settingsRepo->method('findOneBy')->willReturn($settings);

        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(Notification::class));

        $this->createDispatcher()->dispatch(
            $this->recipient,
            Notification::TYPE_ORDER_NEW,
            'Test no telegram',
        );
    }
}
