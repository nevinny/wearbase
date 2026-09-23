<?php

declare(strict_types=1);

namespace App\Tests\Service\Seo;

use App\Service\Seo\SeoQueryGapProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Резолв кандидатных фраз (показы+позиция+our_url) — вынесено из SeoGapReportCommand
 * (см. коммит рефакторинга). Раздельные raw-таблицы (не entity) засеяны напрямую
 * через Connection — мирроринг схемы для SQLite в tests/bootstrap.php.
 */
final class SeoQueryGapProviderTest extends KernelTestCase
{
    private Connection $db;
    private SeoQueryGapProvider $provider;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        $this->provider = self::getContainer()->get(SeoQueryGapProvider::class);

        // Таблицы созданы в tests/bootstrap.php — чистим перед каждым тестом (кросс-тестовая
        // изоляция, SQLite-файл персистентный на весь прогон phpunit).
        foreach (['yandex_query_stats', 'gsc_query_stats', 'yandex_query_page', 'gsc_query_page'] as $t) {
            $this->db->executeStatement("DELETE FROM {$t}");
        }
    }

    public function testFetchBandRowsSplitsStrikingAndGap(): void
    {
        $this->db->executeStatement(
            "INSERT INTO yandex_query_stats (query_text, shows, position, date_to) VALUES
             ('striking query', 100, 5.0, '2026-09-20'),
             ('gap query', 50, 15.0, '2026-09-20'),
             ('below threshold', 5, 20.0, '2026-09-20')",
        );

        $striking = $this->provider->fetchBandRows('striking', 'yandex', 10, 40);
        $gap      = $this->provider->fetchBandRows('gap', 'yandex', 10, 40);

        self::assertCount(1, $striking);
        self::assertSame('striking query', $striking[0]['query']);
        self::assertCount(1, $gap);
        self::assertSame('gap query', $gap[0]['query']);
    }

    public function testFetchBandRowsResolvesOurUrlFromYandexQueryPage(): void
    {
        $this->db->executeStatement(
            "INSERT INTO yandex_query_stats (query_text, shows, position, date_to) VALUES ('bez url', 100, 15.0, '2026-09-20')",
        );
        $this->db->executeStatement(
            "INSERT INTO yandex_query_page (query, page_url, impressions, captured_on) VALUES ('bez url', '/ru/brands/x', 100, '2026-09-20')",
        );

        $rows = $this->provider->fetchBandRows('gap', 'yandex', 10, 40);

        self::assertCount(1, $rows);
        self::assertSame('/ru/brands/x', $rows[0]['page']);
    }

    public function testFetchBandRowsGsc(): void
    {
        $this->db->executeStatement(
            "INSERT INTO gsc_query_stats (query, day, impressions, position) VALUES
             ('gsc gap', '2026-09-20', 80, 12.0),
             ('gsc ok', '2026-09-20', 80, 2.0)",
        );

        $gap = $this->provider->fetchBandRows('gap', 'gsc', 10, 40);

        self::assertCount(1, $gap);
        self::assertSame('gsc gap', $gap[0]['query']);
        self::assertSame('gsc', $gap[0]['source']);
    }

    public function testFetchBandRowsBothMergesYandexAndGsc(): void
    {
        $this->db->executeStatement(
            "INSERT INTO yandex_query_stats (query_text, shows, position, date_to) VALUES ('y query', 100, 15.0, '2026-09-20')",
        );
        $this->db->executeStatement(
            "INSERT INTO gsc_query_stats (query, day, impressions, position) VALUES ('g query', '2026-09-20', 80, 12.0)",
        );

        $rows = $this->provider->fetchBandRows('gap', 'both', 10, 40);

        self::assertCount(2, $rows);
        $sources = array_column($rows, 'source');
        self::assertContains('yandex', $sources);
        self::assertContains('gsc', $sources);
    }

    public function testResolveBandsOrderIsStrikingFirst(): void
    {
        self::assertSame(['striking', 'gap'], $this->provider->resolveBands('both'));
        self::assertSame(['gap'], $this->provider->resolveBands('gap'));
        self::assertSame([], $this->provider->resolveBands('bogus'));
    }

    public function testClassifyGroupGeoCategory(): void
    {
        self::assertSame('geo_category', $this->provider->classifyGroup('бренды одежды спб', []));
    }

    public function testClassifyGroupReplaceComparison(): void
    {
        self::assertSame('replace_comparison', $this->provider->classifyGroup('чем заменить zara', []));
    }

    public function testClassifyGroupNavigationMatchesKnownBrand(): void
    {
        self::assertSame('navigation', $this->provider->classifyGroup('купить cool-brand недорого', ['cool-brand']));
    }

    public function testClassifyGroupOther(): void
    {
        self::assertSame('other', $this->provider->classifyGroup('случайная фраза без интента', []));
    }
}
