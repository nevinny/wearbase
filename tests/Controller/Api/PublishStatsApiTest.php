<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Brand;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /api/v1/publish-stats: queue_pending — реальные кандидаты дрипа (niche/origin-гейты, как
 * BrandRepository::countDripCandidates), queue_raw — прежняя цифра без гейтов.
 */
class PublishStatsApiTest extends WebTestCase
{
    public function testQueuePendingIsGatedAndQueueRawIsNot(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $before = $this->stats($client);

        $ids = [];
        foreach (['ps-plain', 'ps-off-niche', 'ps-foreign'] as $slug) {
            $b = (new Brand())->setTitle($slug)->setSlug($slug . '-' . bin2hex(random_bytes(3)));
            $b->setStatus(Statuses::New);
            $b->setPublishPending(true);
            $em->persist($b);
            $em->flush();
            $ids[$slug] = $b->getId();
        }
        $db = $em->getConnection();
        $db->executeStatement("UPDATE brand SET niche_status = 'off' WHERE id = ?", [$ids['ps-off-niche']]);
        $db->executeStatement("UPDATE brand SET origin_status = 'foreign' WHERE id = ?", [$ids['ps-foreign']]);

        $after = $this->stats($client);

        $this->assertSame(1, $after['queue_pending'] - $before['queue_pending'], 'гейты отсекают niche=off и foreign');
        $this->assertSame(3, $after['queue_raw'] - $before['queue_raw'], 'queue_raw — без гейтов');
    }

    /** @return array<string,mixed> */
    private function stats($client): array
    {
        $client->request('GET', '/api/v1/publish-stats', [], [], ['HTTP_X_AGENT_TOKEN' => 'test-agent-token']);
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsInt($data['queue_pending']);
        $this->assertIsInt($data['queue_raw']);

        return $data;
    }
}
