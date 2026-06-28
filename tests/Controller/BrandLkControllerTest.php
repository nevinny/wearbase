<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Integration tests for the brand personal account area: /brand/*
 *
 * Security rules:
 *  - Guests → 302 redirect to /login  (no DB needed — security layer)
 *  - Regular customers → 403 Forbidden
 *  - Brand managers → 200 OK
 *
 * Run with: php bin/phpunit tests/Controller/BrandLkControllerTest.php
 */
class BrandLkControllerTest extends DatabaseDependentWebTestCase
{
    // ── Access control: guests redirect (no DB needed) ────────────────────────

    #[DataProvider('brandPathsProvider')]
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

    #[DataProvider('brandPathsProvider')]
    public function testCustomerCannotAccessBrandArea(string $path): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        $client->loginUser(UserFactory::makeCustomer());
        $client->request('GET', $path);

        $this->assertResponseStatusCodeSame(403, "Expected 403 for customer at $path");
    }

    // ── Authenticated brand manager pages ─────────────────────────────────────

    public function testBrandDashboardLoads(): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        $client->loginUser(UserFactory::makeBrandManager());
        $client->request('GET', '/brand/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'WEARBASE');
    }

    public function testBrandProfileLoads(): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        $client->loginUser(UserFactory::makeBrandManager());
        $client->request('GET', '/brand/profile');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testBrandProductsLoads(): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        $client->loginUser(UserFactory::makeBrandManager());
        $client->request('GET', '/brand/products');

        $this->assertResponseIsSuccessful();
    }

    public function testBrandOrdersLoads(): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        $client->loginUser(UserFactory::makeBrandManager());
        $client->request('GET', '/brand/orders');

        $this->assertResponseIsSuccessful();
    }

    public function testBrandTeamLoads(): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        $client->loginUser(UserFactory::makeBrandManager());
        $client->request('GET', '/brand/team');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="email"]'); // invite form
    }

    public function testBrandNewProductFormLoads(): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        $client->loginUser(UserFactory::makeBrandManager());
        $client->request('GET', '/brand/products/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }
}
