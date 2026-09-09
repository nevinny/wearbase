<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\WebPushSubscription;

final class WebPushSubscriptionControllerTest extends AuthenticatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    public function testSubscriptionRequiresAuthenticationAndCsrf(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/account/notifications/push-subscriptions', $this->payload('https://push.example/one'));
        $this->assertResponseRedirects('/login');

        $user = UserFactory::withEmail(static::getContainer(), 'push-csrf@test.local');
        $client->loginUser($user);
        $client->jsonRequest('POST', '/account/notifications/push-subscriptions', $this->payload('https://push.example/one'));
        $this->assertResponseStatusCodeSame(403);
    }

    public function testUserCanOptInAndCannotRevokeForeignSubscription(): void
    {
        $client = static::createClient();
        $owner = UserFactory::withEmail(static::getContainer(), 'push-owner@test.local');
        $other = UserFactory::withEmail(static::getContainer(), 'push-other@test.local');
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $foreign = new WebPushSubscription($other, 'https://push.example/foreign-secret', 'foreign-key', 'foreign-auth', 'aes128gcm');
        $em->persist($foreign);
        $em->flush();
        $client->loginUser($owner);
        $crawler = $client->request('GET', '/account/notifications/settings');
        $csrf = $crawler->filter('[data-web-push-settings]')->attr('data-csrf');

        $client->jsonRequest('POST', '/account/notifications/push-subscriptions', $this->payload('https://push.example/owned-secret'), ['HTTP_X_CSRF_TOKEN' => $csrf]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertNotNull($em->getRepository(WebPushSubscription::class)->findByEndpoint('https://push.example/owned-secret'));

        $foreignId = $foreign->getId();
        $client->request('DELETE', '/account/notifications/push-subscriptions/'.$foreignId, server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
        $this->assertResponseStatusCodeSame(404);
        $foreign = $em->getRepository(WebPushSubscription::class)->find($foreignId);
        $this->assertTrue($foreign->isActive());
    }

    /** @return array<string, mixed> */
    private function payload(string $endpoint): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-token'], 'contentEncoding' => 'aes128gcm'];
    }
}
