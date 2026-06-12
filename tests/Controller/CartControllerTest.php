<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests for the cart and checkout flow: /cart/*, /checkout/*
 *
 * Cart pages are public — no DB needed for guest tests.
 * Checkout requires login; authenticated tests need the test DB.
 *
 * Run with: php bin/phpunit tests/Controller/CartControllerTest.php
 */
class CartControllerTest extends WebTestCase
{
    // ── Cart: public, no DB needed ────────────────────────────────────────────

    public function testCartPageReturns200ForGuest(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cart');

        $this->assertResponseIsSuccessful();
    }

    public function testCartPageContainsBranding(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cart');

        $this->assertSelectorTextContains('body', 'WEARBASE');
    }

    public function testCartPageShowsEmptyStateForNewSession(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cart');

        // Fresh session → empty cart message expected
        $this->assertSelectorTextContains('body', 'корзин');
    }

    // ── Cart count JSON endpoint ──────────────────────────────────────────────

    public function testCartCountEndpointReturnsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cart/count');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('count', $data);
        $this->assertIsInt($data['count']);
        $this->assertSame(0, $data['count']); // fresh session → 0
    }

    // ── Checkout: guest must log in ───────────────────────────────────────────

    public function testCheckoutRedirectsGuestToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/checkout');

        $this->assertResponseRedirects('/login');
    }

    // ── Checkout: authenticated but empty cart ────────────────────────────────

    public function testCheckoutWithEmptyCartRedirectsToCart(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::makeCustomer());
        $client->request('GET', '/checkout');

        // Empty cart → should redirect back to /cart
        $this->assertResponseRedirects('/cart');
    }

    // ── Checkout success pages do not 500 ────────────────────────────────────

    public function testCheckoutSuccessMultiPageDoesNotCrash(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::makeCustomer());
        $client->request('GET', '/checkout/success');

        $this->assertNotSame(500, $client->getResponse()->getStatusCode());
    }

    // ── Cart add: POST endpoint returns JSON ──────────────────────────────────

    public function testCartAddEndpointWithBadVariantReturns404(): void
    {
        $client = static::createClient();
        $client->request('POST', '/cart/add/99999999');

        // Unknown variant ID → 404
        $this->assertResponseStatusCodeSame(404);
    }

    // ── Cart page has correct form elements ──────────────────────────────────

    public function testCartPageHasLinkToCatalog(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cart');

        $this->assertSelectorExists('a[href*="catalog"]');
    }
}
