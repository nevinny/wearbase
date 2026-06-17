<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Brand;
use App\Entity\BrandRagPipeline;
use PHPUnit\Framework\TestCase;

/**
 * Контракт предиката isPublishReady() — единственного домен-определения «готов к публикации».
 * Фаза 2 сводит к нему SQL-зеркала (findReadyToPush, дашборд, отчёт); эти тесты фиксируют
 * семантику, чтобы рефакторинг не сдвинул границу публикации.
 */
class BrandRagPipelineTest extends TestCase
{
    private function readyPipeline(): BrandRagPipeline
    {
        $brand = (new Brand())
            ->setTitle('Test')
            ->setSlug('test')
            ->setDescription('Полноценное описание бренда достаточной длины.')
            ->setMetaTitle('Мета-заголовок')
            ->setMetaDescription('Мета-описание для выдачи.');

        return (new BrandRagPipeline())
            ->setBrand($brand)
            ->setStatus(BrandRagPipeline::STATUS_DONE)
            ->setFaqStatus(BrandRagPipeline::FAQ_DONE)
            ->setKeywordsStatus(BrandRagPipeline::KW_FOUND);
    }

    public function testReadyWhenAllConditionsMet(): void
    {
        self::assertTrue($this->readyPipeline()->isPublishReady());
    }

    public function testFaqSkippedAndKeywordsNotFoundStillReady(): void
    {
        $p = $this->readyPipeline()
            ->setFaqStatus(BrandRagPipeline::FAQ_SKIPPED)
            ->setKeywordsStatus(BrandRagPipeline::KW_NOT_FOUND);
        self::assertTrue($p->isPublishReady(), 'skipped FAQ и not_found ключевики не блокируют публикацию');
    }

    public function testNotReadyWhenStatusNotDone(): void
    {
        $p = $this->readyPipeline()->setStatus(BrandRagPipeline::STATUS_EMBEDDED);
        self::assertFalse($p->isPublishReady());
    }

    public function testNotReadyWhenDescriptionEmpty(): void
    {
        $p = $this->readyPipeline();
        $p->getBrand()->setDescription('');
        self::assertFalse($p->isPublishReady());
    }

    public function testNotReadyWhenMetaTitleEmpty(): void
    {
        $p = $this->readyPipeline();
        $p->getBrand()->setMetaTitle('');
        self::assertFalse($p->isPublishReady());
    }

    public function testNotReadyWhenMetaDescriptionEmpty(): void
    {
        $p = $this->readyPipeline();
        $p->getBrand()->setMetaDescription('');
        self::assertFalse($p->isPublishReady());
    }

    public function testNotReadyWhenFaqPending(): void
    {
        $p = $this->readyPipeline()->setFaqStatus(null);
        self::assertFalse($p->isPublishReady());
    }

    public function testNotReadyWhenKeywordsPending(): void
    {
        $p = $this->readyPipeline()->setKeywordsStatus(null);
        self::assertFalse($p->isPublishReady());
    }

    public function testNotReadyWhenNoBrand(): void
    {
        $p = (new BrandRagPipeline())->setStatus(BrandRagPipeline::STATUS_DONE);
        self::assertFalse($p->isPublishReady());
    }

    // --- markEmbedded(): guard против лоссового демоута (регресс дренаж-бага 06-06) ---

    public function testMarkEmbeddedFromScrapedAdvances(): void
    {
        $p = (new BrandRagPipeline())->setStatus(BrandRagPipeline::STATUS_SCRAPED)->markEmbedded();
        self::assertSame(BrandRagPipeline::STATUS_EMBEDDED, $p->getStatus());
        self::assertNotNull($p->getEmbeddedAt());
    }

    public function testMarkEmbeddedDoesNotDemoteDone(): void
    {
        $p = (new BrandRagPipeline())->setStatus(BrandRagPipeline::STATUS_DONE)->markEmbedded();
        self::assertSame(BrandRagPipeline::STATUS_DONE, $p->getStatus(), 'done не демотится ре-эмбедом');
        self::assertNotNull($p->getEmbeddedAt(), 'но embeddedAt освежается (аудит переэмбеда)');
    }
}
