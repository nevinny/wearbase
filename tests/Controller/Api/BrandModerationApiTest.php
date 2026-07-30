<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Brand;
use App\Entity\BrandLink;
use App\Entity\BrandModeration;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Агент-API премодерации (/api/v1/moderation/*). Аутентификация — X-Agent-Token
 * (см. BrandIngestController::authorize), тот же паттерн, что у revalidation-queue.
 */
class BrandModerationApiTest extends WebTestCase
{
    private const TOKEN  = 'test-agent-token';
    private const SECRET = 'test-agent-secret';

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

    public function testVerdictMinimalPayloadReturns200AndPersistsModeration(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $brand = (new Brand())->setTitle('Русский бренд Ах')->setSlug('russkii-brend-akh');
        $brand->setStatus(Statuses::Active);
        $em->persist($brand);
        $em->flush();

        $body = json_encode([
            'slug'            => 'russkii-brend-akh',
            'verdict'         => 'request_changes',
            'identity_match'  => 'confirmed',
            'control_proof'   => 'unconfirmed',
        ], JSON_THROW_ON_ERROR);

        $this->postVerdict($client, $body);

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('reviewed', $data['status']);
        $this->assertSame($brand->getId(), $data['brand_id']);
        $this->assertSame(1, $data['analyze_attempts']);

        $moderation = $em->getRepository(BrandModeration::class)->findOneBy(['brand' => $brand]);
        $this->assertNotNull($moderation);
        $this->assertSame(BrandModeration::STATUS_REVIEWED, $moderation->getStatus());
        $this->assertSame('request_changes', $moderation->getVerdict());
        $this->assertSame('confirmed', $moderation->getIdentityMatch());
        $this->assertSame('unconfirmed', $moderation->getControlProof());
        $this->assertSame(BrandModeration::SOURCE_MANUAL, $moderation->getSource());
    }

    public function testVerdictWithLinksCreatesBrandLinkAndSkipsDuplicates(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $brand = (new Brand())->setTitle('Бренд со ссылками')->setSlug('brand-with-links');
        $brand->setStatus(Statuses::Active);
        $em->persist($brand);

        $existing = (new BrandLink())->setBrand($brand)->setLinkUrl('https://vk.com/existing')->setLinkType('vk');
        $existing->setTitle('vk');
        $existing->setSlug('existing-owner-link');
        $existing->setStatus(Statuses::Active);
        $em->persist($existing);
        $em->flush();
        // KernelBrowser не ребутает kernel на первом запросе клиента → без clear() контроллер
        // получил бы из identity map тот же PHP-объект $brand с непрогруженной инверс-стороной
        // links (мы не звали $brand->addLink()) — тест-артефакт, не воспроизводимый в проде
        // (там каждый HTTP-запрос всегда хидратирует бренд заново).
        $em->clear();

        $body = json_encode([
            'slug'    => 'brand-with-links',
            'verdict' => 'approve',
            'links'   => [
                ['link_type' => 'instagram', 'link_url' => 'https://instagram.com/newbrand'],
                ['link_type' => 'vk', 'link_url' => 'https://vk.com/existing'], // дубль — не создаём
            ],
        ], JSON_THROW_ON_ERROR);

        $this->postVerdict($client, $body);
        $this->assertResponseIsSuccessful();

        $em->clear();
        $refreshed = $em->getRepository(Brand::class)->findOneBy(['slug' => 'brand-with-links']);
        $urls = array_map(static fn (BrandLink $l) => $l->getLinkUrl(), $refreshed->getLinks()->toArray());
        sort($urls);
        $this->assertSame(['https://instagram.com/newbrand', 'https://vk.com/existing'], $urls);

        // Повторный вызов не дублирует уже существующую (в т.ч. новую) ссылку.
        $client->request(
            'POST',
            '/api/v1/moderation/verdict',
            [],
            [],
            $this->signedHeaders($body),
            $body,
        );
        $this->assertResponseIsSuccessful();
        $em->clear();
        $refreshed = $em->getRepository(Brand::class)->findOneBy(['slug' => 'brand-with-links']);
        $this->assertCount(2, $refreshed->getLinks());
    }

    public function testVerdictWithNicheAndOriginStatusUpdatesBrand(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $brand = (new Brand())->setTitle('Нишевый бренд')->setSlug('niche-brand');
        $brand->setStatus(Statuses::Active);
        $em->persist($brand);
        $em->flush();

        $body = json_encode([
            'slug'          => 'niche-brand',
            'verdict'       => 'approve',
            'niche_status'  => 'in',
            'origin_status' => 'ru',
        ], JSON_THROW_ON_ERROR);

        $this->postVerdict($client, $body);
        $this->assertResponseIsSuccessful();

        $em->clear();
        $refreshed = $em->getRepository(Brand::class)->findOneBy(['slug' => 'niche-brand']);
        $this->assertSame('in', $refreshed->getNicheStatus());
        $this->assertSame('ru', $refreshed->getOriginStatus());
    }

    public function testVerdictUnknownSlugReturnsNotFound(): void
    {
        $client = static::createClient();
        $body = json_encode(['slug' => 'no-such-brand-slug', 'verdict' => 'approve'], JSON_THROW_ON_ERROR);

        $this->postVerdict($client, $body);

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('not_found', $data['status']);
    }

    public function testVerdictMissingSlugReturns422(): void
    {
        $client = static::createClient();
        $body = json_encode(['verdict' => 'approve'], JSON_THROW_ON_ERROR);

        $this->postVerdict($client, $body);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testVerdictInvalidJsonReturns400(): void
    {
        $client = static::createClient();
        $body = '{not-json';

        $this->postVerdict($client, $body);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testVerdictWithBadSignatureReturns401(): void
    {
        $client = static::createClient();
        $body = json_encode(['slug' => 'russkii-brend-akh', 'verdict' => 'approve'], JSON_THROW_ON_ERROR);

        $client->request(
            'POST',
            '/api/v1/moderation/verdict',
            [],
            [],
            [
                'HTTP_X_AGENT_TOKEN' => self::TOKEN,
                'HTTP_X_SIGNATURE'   => 'deadbeef',
                'CONTENT_TYPE'       => 'application/json',
            ],
            $body,
        );

        $this->assertResponseStatusCodeSame(401);
    }

    private function postVerdict(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $body): void
    {
        $client->request(
            'POST',
            '/api/v1/moderation/verdict',
            [],
            [],
            $this->signedHeaders($body),
            $body,
        );
    }

    /** @return array<string,string> */
    private function signedHeaders(string $body): array
    {
        return [
            'HTTP_X_AGENT_TOKEN' => self::TOKEN,
            'HTTP_X_SIGNATURE'   => hash_hmac('sha256', $body, self::SECRET),
            'CONTENT_TYPE'       => 'application/json',
        ];
    }
}
