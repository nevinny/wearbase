<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Brand;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use PHPUnit\Framework\TestCase;

/**
 * Машина состояний бренда: инварианты доменных переходов (без БД).
 */
class BrandStatusTest extends TestCase
{
    public function testQueueSetsNewAndPending(): void
    {
        $b = (new Brand())->queue();
        $this->assertSame(Statuses::New, $b->getStatus());
        $this->assertTrue($b->isPublishPending());
    }

    public function testPublishFromNew(): void
    {
        $b = (new Brand())->queue();
        $this->assertTrue($b->publish());
        $this->assertSame(Statuses::Active, $b->getStatus());
        $this->assertFalse($b->isPublishPending());
        $this->assertNotNull($b->getPublishedAt());
    }

    public function testPublishFromDisabled(): void
    {
        $b = new Brand();
        $b->setStatus(Statuses::Disabled);
        $this->assertTrue($b->publish());
        $this->assertSame(Statuses::Active, $b->getStatus());
    }

    public function testPublishIdempotentOnActive(): void
    {
        $b = new Brand();
        $b->setStatus(Statuses::Active);
        $this->assertFalse($b->publish(), 'повтор publish на active — no-op');
        $this->assertSame(Statuses::Active, $b->getStatus());
    }

    public function testPublishUsesGivenTimestamp(): void
    {
        $at = new \DateTime('2026-01-02 03:04:05');
        $b = (new Brand())->queue();
        $b->publish($at);
        $this->assertSame($at, $b->getPublishedAt());
    }

    public function testPublishFromDeletedThrows(): void
    {
        $b = new Brand();
        $b->setStatus(Statuses::Deleted);
        $this->expectException(\DomainException::class);
        $b->publish();
    }

    public function testUnpublishFromActive(): void
    {
        $b = new Brand();
        $b->setStatus(Statuses::Active);
        $this->assertTrue($b->unpublish());
        $this->assertSame(Statuses::Disabled, $b->getStatus());
        $this->assertFalse($b->isPublishPending());
    }

    public function testUnpublishIdempotentOnDisabled(): void
    {
        $b = new Brand();
        $b->setStatus(Statuses::Disabled);
        $this->assertFalse($b->unpublish(), 'повтор unpublish на disabled — no-op');
    }

    public function testSoftDelete(): void
    {
        $b = (new Brand())->queue();
        $b->softDelete();
        $this->assertSame(Statuses::Deleted, $b->getStatus());
        $this->assertFalse($b->isPublishPending());
    }
}
