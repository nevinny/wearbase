<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\NewsItem;
use App\Entity\NewsSource;
use App\Enum\NewsItemStatus;
use App\Enum\NewsRubric;
use App\Enum\TosMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Публичные страницы /news и /news/{slug}: индексируемость, self-canonical,
 * обязательная атрибуция «По материалам …» со ссылкой на первоисточник.
 */
final class NewsControllerTest extends DatabaseDependentWebTestCase
{
    private const SOURCE_NAME = 'Parents.ru';
    private const SOURCE_URL = 'https://www.parents.ru/news/original-story';

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
        $this->em()->createQuery('DELETE FROM App\Entity\NewsItem')->execute();
        $this->em()->createQuery('DELETE FROM App\Entity\NewsSource')->execute();
        static::ensureKernelShutdown();
    }

    public function testPublishedArticleShowsAttributionAndSelfCanonical(): void
    {
        $this->insertItem(NewsItemStatus::Published, 'kapsula-iz-staryh-dzhinsov', 'Капсула из старых джинсов');

        $client = static::createClient();
        $client->request('GET', '/news/kapsula-iz-staryh-dzhinsov');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        // Атрибуция первоисточника; ссылка постоянная (без nofollow)
        self::assertStringContainsString('По материалам', $html);
        self::assertStringContainsString(self::SOURCE_NAME, $html);
        self::assertStringContainsString('<a href="' . self::SOURCE_URL . '"', $html);
        self::assertStringNotContainsString('nofollow', $html);

        // Self-canonical + нет noindex
        // Self-canonical = URL текущей страницы (в тестовом окружении хост — localhost)
        self::assertStringContainsString('rel="canonical" href="http://localhost/news/kapsula-iz-staryh-dzhinsov"', $html);
        self::assertStringNotContainsString('noindex', $html);

        self::assertStringContainsString('Капсула из старых джинсов', $html);
    }

    public function testIndexListsPublishedItems(): void
    {
        $this->insertItem(NewsItemStatus::Published, 'novost-pervaya', 'Новость первая');
        $this->insertItem(NewsItemStatus::Ready, null, 'Черновая новость — не публикуется');

        $client = static::createClient();

        $client->request('GET', '/news');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Новость первая', $html);
        self::assertStringNotContainsString('Черновая новость', $html, 'ready-черновики не видны на сайте');
        self::assertStringNotContainsString('noindex', $html);

        // Прямой заход на страницу 2 короткой ленты не падает
        $client->request('GET', '/news?page=2');
        self::assertResponseIsSuccessful();
    }

    public function testUnpublishedSlugReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/news/takoj-novosti-net');
        self::assertResponseStatusCodeSame(404);
    }

    private function insertItem(NewsItemStatus $status, ?string $slug, string $title): void
    {
        $em = $this->em();
        $source = $em->getRepository(NewsSource::class)->findOneBy(['name' => self::SOURCE_NAME]);
        if ($source === null) {
            $source = (new NewsSource())
                ->setName(self::SOURCE_NAME)
                ->setFeedUrl('https://www.parents.test/rss.xml')
                ->setTosMode(TosMode::FactsOnly)
                ->setActive(true);
            $em->persist($source);
            $em->flush();
        }

        static $seq = 0;
        ++$seq;

        $item = (new NewsItem())
            ->setSource($source)
            ->setGuidHash('hash-' . $seq . '-' . bin2hex(random_bytes(4)))
            ->setUrl(self::SOURCE_URL)
            ->setTitle('Оригинальный заголовок ' . $seq)
            ->setSourceName(self::SOURCE_NAME)
            ->setSourceUrl(self::SOURCE_URL)
            ->setRewrittenTitle($title)
            ->setRewrittenBody("Первый абзац заметки про гардероб.\n\nВторой абзац с фактами.")
            ->setRubric(NewsRubric::Wardrobe)
            ->setStatus($status);
        if ($slug !== null && $status === NewsItemStatus::Published) {
            $item->setSlug($slug);
        }
        $em->persist($item);
        $em->flush();

        // Данные в файловой SQLite переживают shutdown; createClient требует незагруженный kernel.
        static::ensureKernelShutdown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }
}
