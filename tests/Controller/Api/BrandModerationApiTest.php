<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Агент-API премодерации (/api/v1/moderation/*). Аутентификация — X-Agent-Token
 * (см. BrandIngestController::authorize), тот же паттерн, что у revalidation-queue.
 */
class BrandModerationApiTest extends WebTestCase
{
    public function testQueueWithoutTokenReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/moderation/queue');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testQueueWithValidTokenReturnsItems(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/moderation/queue', [], [], ['HTTP_X_AGENT_TOKEN' => 'test-agent-token']);

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('items', $data);
    }
}
