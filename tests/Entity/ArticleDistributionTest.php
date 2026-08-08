<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ArticleDistribution;
use PHPUnit\Framework\TestCase;

class ArticleDistributionTest extends TestCase
{
    public function testPublicationMetadataBelongsToDistributionVersion(): void
    {
        $publishedAt = new \DateTime('2026-07-23 12:00:00');
        $distribution = (new ArticleDistribution())
            ->setPublishedAt($publishedAt)
            ->setExternalUrl('https://dzen.ru/a/example');

        self::assertSame($publishedAt, $distribution->getPublishedAt());
        self::assertSame('https://dzen.ru/a/example', $distribution->getExternalUrl());
    }
}
