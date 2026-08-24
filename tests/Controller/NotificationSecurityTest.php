<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Notification;

class NotificationSecurityTest extends AuthenticatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    public function testMarkReadRequiresPostAndCsrf(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), 'notification-security@test.local');
        $notification = (new Notification())
            ->setRecipient($user)
            ->setType(Notification::TYPE_SYSTEM)
            ->setTitle('Проверка');
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist($notification);
        $em->flush();
        $client->loginUser($user);

        $client->request('GET', '/account/notifications/mark-read/'.$notification->getId());
        $this->assertResponseStatusCodeSame(405);

        $client->request('POST', '/account/notifications/mark-read/'.$notification->getId(), ['_token' => 'invalid']);
        $this->assertResponseStatusCodeSame(403);
        $notification = $em->getRepository(Notification::class)->find($notification->getId());
        $this->assertFalse($notification->isRead());

        $crawler = $client->request('GET', '/account/notifications');
        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $form = $crawler->filter(sprintf('form[action="/account/notifications/mark-read/%d"]', $notification->getId()))->form();
        $client->submit($form);
        $em->clear();
        $notification = $em->getRepository(Notification::class)->find($notification->getId());
        $this->assertTrue($notification->isRead());
    }

    public function testUserCannotMarkAnotherUsersNotificationAsRead(): void
    {
        $client = static::createClient();
        $owner = UserFactory::withEmail(static::getContainer(), 'notification-owner@test.local');
        $actor = UserFactory::withEmail(static::getContainer(), 'notification-idor@test.local');
        $notification = (new Notification())
            ->setRecipient($actor)
            ->setType(Notification::TYPE_PURCHASE_BOUGHT)
            ->setTitle('Выкуплена вещь');
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist($notification);
        $em->flush();
        $client->loginUser($actor);
        $crawler = $client->request('GET', '/account/notifications');
        $form = $crawler->filter(sprintf('form[action="/account/notifications/mark-read/%d"]', $notification->getId()))->form();
        $notification->setRecipient($owner);
        $em->flush();

        $client->submit($form);

        $this->assertResponseRedirects('/account/notifications');
        $em->clear();
        $this->assertFalse($em->getRepository(Notification::class)->find($notification->getId())->isRead());
    }

    public function testUnsafeInternalNotificationUrlIsNotRendered(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), 'notification-url@test.local');
        $notification = (new Notification())
            ->setRecipient($user)
            ->setType(Notification::TYPE_SYSTEM)
            ->setTitle('Опасная ссылка')
            ->setData(['url' => '/account/../admin']);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist($notification);
        $em->flush();
        $client->loginUser($user);

        $client->request('GET', '/account/notifications');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('a[href="/account/../admin"]');
    }

    public function testReminderSettingsExposeOnlyInAppChannel(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), 'notification-reminder-settings@test.local');
        $client->loginUser($user);

        $client->request('GET', '/account/notifications/settings');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('input[name="settings[purchase_decision_reminder][channelEmail]"]');
        $this->assertSelectorNotExists('input[name="settings[purchase_decision_reminder][channelTelegram]"]');
        $this->assertSelectorExists('input[name="settings[purchase_decision_reminder][channelInapp]"]');
        $this->assertSelectorNotExists('input[name="settings[purchase_fitting_reminder][channelEmail]"]');
        $this->assertSelectorNotExists('input[name="settings[purchase_fitting_reminder][channelTelegram]"]');
        $this->assertSelectorExists('input[name="settings[purchase_fitting_reminder][channelInapp]"]');
        $this->assertSelectorExists('input[name="settings[purchase_bought][channelTelegram]"]');
    }
}
