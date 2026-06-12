<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for the brand personal account area: /brand/*
 *
 * Security rules:
 *  - Guests → 302 redirect to /login  (no DB needed — security layer)
 *  - Regular customers → 403 Forbidden
 *  - Brand managers → 200 OK
 *
 * Setup for integration tests:
 *   php bin/console doctrine:database:create --env=test
 *   php bin/console doctrine:migrations:migrate --env=test --no-interaction
 *
 * Run with: php bin/phpunit tests/Controller/BrandLkControllerTest.php
 */
class BrandLkControllerTest extends WebTestCase
{
    private static bool $dbAvailable = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        try {
            $client = static::createClient();
            $em = $client->getContainer()->get('doctrine.orm.entity_manager');
            $em->getConnection()->executeQuery('SELECT 1');
            static::$dbAvailable = true;
        } catch (\Throwable) {
            static::$dbAvailable = false;
        }
    }

    // ── Access control: guests redirect (no DB needed) ────────────────────────

    /**
     * @dataProvider brandPathsProvider
     */
    public function testGuestIsRedirectedToLogin(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        $this->assertResponseRedirects('/login', 302, "Expected 302 for $path");
    }

    public static function brandPathsProvider(): iterable
    {
        yield 'brand dashboard' => ['/brand/dashboard'];
        yield 'brand profile'   => ['/brand/profile'];
        yield 'brand products'  => ['/brand/products'];
        yield 'brand orders'    => ['/brand/orders'];
        yield 'brand team'      => ['/brand/team'];
        yield 'brand media'     => ['/brand/media'];
    }

    // ── Access control: customer gets 403 ────────────────────────────────────

    /**
     * @dataProvider brandPathsProvider
     */
    public function testCustomerCannotAccessBrandArea(string $path): void
    {
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Test database not available.');
        }

        $client = static::createClient();
        $client->loginUser(UserFactory::makeCustomer());
        $client->request('GET', $path);

        $this->assertResponseStatusCodeSame(403, "Expected 403 for customer at $path");
    }

    // ── Authenticated brand manager pages ─────────────────────────────────────

    public function testBrandDashboardLoads(): void
    {
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Test database not available.');
        }

        $client = static::createClient();
        $client->loginUser(UserFactory::makeBrandManager());
        $client->request('GET', '/brand/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'WEARBASE');
    }

    public function testBrandProfileLoads(): void
    {
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Test database not available.');
        }

        $client = static::createClient();
        $client->loginUser(UserFactory::makeBrandManager());
        $client->request('GET', '/brand/profile');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testBrandProductsLoads(): void
    {
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Test database not available.');
        }

        $client = static::createClient();
        $client->loginUser(UserFactory::makeBrandManager());
        $client->request('GET', '/brand/products');

        $this->assertResponseIsSuccessful();
    }

    public function testBrandOrdersLoads(): void
    {
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Test database not available.');
        }

        $client = static::createClient();
        $client->loginUser(UserFactory::makeBrandManager());
        $client->request('GET', '/brand/orders');

        $this->assertResponseIsSuccessful();
    }

    public function testBrandTeamLoads(): void
    {
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Test database not available.');
        }

        $client = static::createClient();
        $client->loginUser(UserFactory::makeBrandManager());
        $client->request('GET', '/brand/team');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="email"]'); // invite form
    }

    public function testBrandNewProductFormLoads(): void
    {
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Test database not available.');
        }

        $client = static::createClient();
        $client->loginUser(UserFactory::makeBrandManager());
        $client->request('GET', '/brand/products/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }
}
