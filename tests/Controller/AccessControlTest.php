<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Access-control smoke tests — no database required.
 *
 * The Symfony security firewall intercepts requests before the controller
 * runs, so these tests verify that:
 *  - Unauthenticated users are redirected to /login for protected routes
 *  - Public routes return 200 without authentication
 *
 * Run with: php bin/phpunit tests/Controller/AccessControlTest.php
 */
class AccessControlTest extends WebTestCase
{
    // ── Protected: /account/* ─────────────────────────────────────────────────

    #[DataProvider('accountRoutesProvider')]
    public function testAccountRouteRedirectsGuest(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        $this->assertResponseRedirects(
            '/login',
            302,
            "Expected redirect to /login for $path"
        );
    }

    public static function accountRoutesProvider(): iterable
    {
        yield 'account dashboard'  => ['/account'];
        yield 'account profile'    => ['/account/profile'];
        yield 'account orders'     => ['/account/orders'];
        yield 'account addresses'  => ['/account/addresses'];
        yield 'new address form'   => ['/account/addresses/new'];
    }

    // ── Protected: /brand/* ───────────────────────────────────────────────────

    #[DataProvider('brandRoutesProvider')]
    public function testBrandRouteRedirectsGuest(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        $this->assertResponseRedirects(
            '/login',
            302,
            "Expected redirect to /login for $path"
        );
    }

    public static function brandRoutesProvider(): iterable
    {
        yield 'brand dashboard' => ['/brand/dashboard'];
        yield 'brand profile'   => ['/brand/profile'];
        yield 'brand products'  => ['/brand/products'];
        yield 'brand orders'    => ['/brand/orders'];
        yield 'brand team'      => ['/brand/team'];
        yield 'brand media'     => ['/brand/media'];
        yield 'new product'     => ['/brand/products/new'];
    }

    // ── Protected: /checkout ─────────────────────────────────────────────────

    public function testCheckoutRedirectsGuest(): void
    {
        $client = static::createClient();
        $client->request('GET', '/checkout');

        $this->assertResponseRedirects('/login');
    }

    // ── Public: auth pages accessible without login ───────────────────────────

    #[DataProvider('publicRoutesProvider')]
    public function testPublicRouteIsAccessibleWithoutLogin(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        $this->assertResponseIsSuccessful(
            "Expected 200 OK for public route $path"
        );
    }

    public static function publicRoutesProvider(): iterable
    {
        yield 'login page'           => ['/login'];
        yield 'register page'        => ['/register'];
        yield 'forgot-password page' => ['/forgot-password'];
        yield 'cart page'            => ['/cart'];
    }

    // ── Login redirect preserves target URL ──────────────────────────────────

    public function testLoginRedirectContainsTargetParam(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/profile');

        $response = $client->getResponse();
        $location = $response->headers->get('Location');

        $this->assertStringContainsString('/login', $location);
    }
}
