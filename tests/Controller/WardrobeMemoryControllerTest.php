<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\WardrobeMemoryFact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final class WardrobeMemoryControllerTest extends AuthenticatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    public function testOwnerCanEditExportAndSoftDeleteFact(): void
    {
        $client = static::createClient();
        $user = $this->loginAsCustomer($client);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $fact = new WardrobeMemoryFact($user, $user, WardrobeMemoryFact::SOURCE_WEAR, random_int(100000, 999999), 'self', 'Старый факт');
        $em->persist($fact);
        $em->flush();

        $client->request('GET', '/account/wardrobe/memory');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Старый факт');
        $token = $this->forceCsrfToken($client->getRequest(), 'wardrobe_memory_'.$fact->getId());
        $client->request('POST', '/account/wardrobe/memory/'.$fact->getId().'/edit', [
            '_token' => $token,
            'fact' => 'Мой исправленный факт',
        ]);
        self::assertResponseRedirects('/account/wardrobe/memory');

        $client->request('GET', '/account/wardrobe/memory/export');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Мой исправленный факт', $payload['facts'][0]['fact']);
        self::assertArrayNotHasKey('source_id', $payload['facts'][0]);

        $client->request('GET', '/account/wardrobe/memory');
        $token = $this->forceCsrfToken($client->getRequest(), 'wardrobe_memory_'.$fact->getId());
        $client->request('POST', '/account/wardrobe/memory/'.$fact->getId().'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/account/wardrobe/memory');
        $deleted = static::getContainer()->get(EntityManagerInterface::class)->find(WardrobeMemoryFact::class, $fact->getId());
        self::assertTrue($deleted->isDeleted());
        self::assertTrue($deleted->isDeletedByUser());
        self::assertSame('[deleted]', $deleted->getFact());
    }

    public function testForeignProfileIsNotReadable(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);
        $foreign = UserFactory::withEmail(static::getContainer(), 'memory-foreign@test.local');

        $client->request('GET', '/account/wardrobe/memory?member='.$foreign->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testForeignFactIdIsHidden(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);
        $foreign = UserFactory::withEmail(static::getContainer(), 'memory-fact-foreign@test.local');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $fact = new WardrobeMemoryFact($foreign, $foreign, WardrobeMemoryFact::SOURCE_WEAR, random_int(100000, 999999), 'self', 'Чужой факт');
        $em->persist($fact);
        $em->flush();

        $client->request('GET', '/account/wardrobe/memory');
        $token = $this->forceCsrfToken($client->getRequest(), 'wardrobe_memory_'.$fact->getId());
        $client->request('POST', '/account/wardrobe/memory/'.$fact->getId().'/delete', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
    }

    private function forceCsrfToken(Request $lastRequest, string $tokenId): string
    {
        $requestStack = static::getContainer()->get('request_stack');
        $requestStack->push($lastRequest);
        $token = static::getContainer()->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        $requestStack->pop();
        $lastRequest->getSession()->save();
        return $token;
    }
}
