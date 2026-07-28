<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeoTechAuditCommand;
use App\Notification\AdminNotifier;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Тех-аудит (app:seo:tech-audit) на синтетическом сайте через MockHttpClient — сеть не
 * нужна. Прогон только с --stdout-only: seo_tech_finding — сырая (не-entity) таблица,
 * в SQLite-схему тестов она не провижинится, а проверять здесь надо логику обхода и
 * правил, а не upsert.
 */
class SeoTechAuditCommandTest extends KernelTestCase
{
    /** @param array<string,string> $pages путь → HTML (или XML для /sitemap.xml) */
    private function tester(array $pages): CommandTester
    {
        self::bootKernel();

        $client = new MockHttpClient(static function (string $method, string $url) use ($pages): MockResponse {
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

            return isset($pages[$path])
                ? new MockResponse($pages[$path], ['http_code' => 200])
                : new MockResponse('not found', ['http_code' => 404]);
        }, 'https://example.test');

        $command = new SeoTechAuditCommand(
            $client,
            self::getContainer()->get(Connection::class),
            self::getContainer()->get(AdminNotifier::class),
            'https://example.test',
        );

        return new CommandTester($command);
    }

    private static function page(string $title, string $body, string $head = ''): string
    {
        return <<<HTML
            <html><head>
                <title>{$title}</title>
                <meta name="description" content="Достаточно длинное описание страницы, чтобы правило про короткий description не срабатывало на тестовых данных.">
                <link rel="canonical" href="https://example.test/">
                {$head}
            </head><body>{$body}</body></html>
            HTML;
    }

    public function testDetectsChecklistViolations(): void
    {
        $tester = $this->tester([
            '/' => self::page('Главная', '<h1>Главная</h1><a href="/ru/bad">bad</a><a href="/ru/ok">ok</a>'),
            // два H1, CTA заголовком, FAQ без разметки, картинка без alt, короткий description
            '/ru/bad' => <<<HTML
                <html><head><title>Плохая</title>
                    <meta name="description" content="Коротко">
                    <link rel="canonical" href="https://example.test/ru/bad">
                </head><body>
                    <h1>Первый</h1><h1>Второй</h1>
                    <h2>Оставить заявку</h2>
                    <h3>Частые вопросы</h3>
                    <img src="/a.png">
                </body></html>
                HTML,
            '/ru/ok'       => self::page('Хорошая', '<h1>Хорошая</h1><img src="/b.png" alt="описание">'),
            '/sitemap.xml' => '<urlset><url><loc>https://example.test/</loc></url></urlset>',
        ]);

        $tester->execute(['--stdout-only' => true, '--delay-ms' => '0', '--link-check-cap' => '0']);
        $out = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Больше одного H1', $out);
        self::assertStringContainsString('CTA оформлен заголовком', $out);
        self::assertStringContainsString('Блок FAQ без JSON-LD FAQPage', $out);
        self::assertStringContainsString('Слишком короткий description', $out);
        self::assertStringContainsString('Картинки без alt', $out);
        // Правила сработали на /ru/bad, а не на здоровой странице
        self::assertStringContainsString('/ru/bad', $out);
        self::assertStringNotContainsString('/ru/ok —', $out);
    }

    public function testFaqWithJsonLdIsNotFlaggedAndNoindexIsSkipped(): void
    {
        $tester = $this->tester([
            '/' => self::page('Главная', '<h1>Главная</h1><a href="/ru/faq">faq</a><a href="/cart">cart</a>'),
            '/ru/faq' => self::page(
                'С разметкой',
                '<h1>С разметкой</h1><h2>Частые вопросы</h2>',
                '<script type="application/ld+json">{"@type":"FAQPage"}</script>',
            ),
            // noindex: ни title-дубль, ни отсутствие меты не должны попадать в чек-лист
            '/cart' => '<html><head><meta name="robots" content="noindex, nofollow"><title>Главная</title></head><body></body></html>',
            '/sitemap.xml' => '<urlset><url><loc>https://example.test/</loc></url></urlset>',
        ]);

        $tester->execute(['--stdout-only' => true, '--delay-ms' => '0', '--link-check-cap' => '0']);
        $out = $tester->getDisplay();

        self::assertStringNotContainsString('Блок FAQ без JSON-LD FAQPage', $out);
        self::assertStringNotContainsString('Нет description', $out);
        self::assertStringNotContainsString('Нет canonical', $out);
        self::assertStringNotContainsString('Одинаковый <title>', $out);
    }

    public function testOrphanIsReportedOnlyForSitemapPageWithoutInboundLinks(): void
    {
        $tester = $this->tester([
            '/'             => self::page('Главная', '<h1>Главная</h1><a href="/ru/blog">блог</a>'),
            '/ru/blog'      => self::page('Блог', '<h1>Блог</h1><a href="/ru/blog/linked">статья</a>'),
            '/ru/blog/linked' => self::page('Слинкованная', '<h1>Слинкованная</h1>'),
            '/ru/blog/orphan' => self::page('Сирота', '<h1>Сирота</h1>'),
            '/sitemap.xml'  => '<urlset>'
                . '<url><loc>https://example.test/ru/blog/linked</loc></url>'
                . '<url><loc>https://example.test/ru/blog/orphan</loc></url>'
                . '</urlset>',
        ]);

        $tester->execute(['--stdout-only' => true, '--delay-ms' => '0', '--link-check-cap' => '0']);
        $out = $tester->getDisplay();

        self::assertStringContainsString('Страница-сирота', $out);
        self::assertStringContainsString('/ru/blog/orphan', $out);
        self::assertStringNotContainsString('/ru/blog/linked —', $out);
    }

    public function testOrphanCheckIsSkippedWhenHubPhaseHitsItsCap(): void
    {
        $tester = $this->tester([
            '/'            => self::page('Главная', '<h1>Главная</h1><a href="/ru/a">a</a><a href="/ru/b">b</a>'),
            '/ru/a'        => self::page('A', '<h1>A</h1>'),
            '/ru/b'        => self::page('B', '<h1>B</h1>'),
            '/sitemap.xml' => '<urlset><url><loc>https://example.test/ru/orphan</loc></url></urlset>',
        ]);

        // Бюджет фазы хабов кончается раньше очереди → «сироты» были бы ложными.
        $tester->execute(['--stdout-only' => true, '--delay-ms' => '0', '--link-check-cap' => '0', '--hub-limit' => '1']);
        $out = $tester->getDisplay();

        self::assertStringContainsString('Проверка сирот пропущена', $out);
        self::assertStringNotContainsString('Страница-сирота', $out);
    }
}
