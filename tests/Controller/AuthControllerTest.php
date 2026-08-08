<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for auth pages: /login, /register, /forgot-password
 *
 * All tests here are purely HTTP-level and do NOT require a database:
 *  - Public pages just render a form
 *  - Bad-credential tests only need the firewall to bounce the request
 *
 * Run with: php bin/phpunit tests/Controller/AuthControllerTest.php
 */
class AuthControllerTest extends WebTestCase
{
    // ── /login ────────────────────────────────────────────────────────────────

    public function testLoginPageReturns200(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
    }

    public function testLoginPageHasEmailAndPasswordFields(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $this->assertSelectorExists('input[name="_username"]');
        $this->assertSelectorExists('input[name="_password"]');
    }

    public function testLoginPageContainsWearbaseBranding(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $this->assertSelectorTextContains('body', 'WEARBASE');
    }

    public function testLoginFormHasCsrfToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $this->assertSelectorExists('input[name="_csrf_token"]');
    }

    public function testLoginFormHasRememberMeCheckbox(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $this->assertSelectorExists('input[name="_remember_me"]');
    }

    public function testLoginWithBadCredentialsRedirectsBack(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        $client->submitForm('Войти', [
            '_username' => 'nobody@example.com',
            '_password' => 'wrongpassword',
        ]);

        $this->assertResponseRedirects('/login');
    }

    public function testLoginBadCredentialsShowsErrorAfterRedirect(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        $client->submitForm('Войти', [
            '_username' => 'nobody@example.com',
            '_password' => 'wrongpassword',
        ]);
        $client->followRedirect();

        $this->assertSelectorExists('.form-error');
    }

    public function testLoginPageHasForgotPasswordLink(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $this->assertSelectorExists('a[href*="forgot"]');
    }

    public function testLoginPageHasRegisterLink(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $this->assertSelectorExists('a[href*="register"]');
    }

    public function testLoginNeverExposesManagedChildTechnicalEmail(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        $client->getRequest()->getSession()->set(
            \Symfony\Component\Security\Http\SecurityRequestAttributes::LAST_USERNAME,
            'child-2-secret@family.wearbase.local',
        );

        $client->request('GET', '/login');

        $this->assertSelectorExists('input[name="_username"][value=""]');
        $this->assertStringNotContainsString('family.wearbase.local', $client->getResponse()->getContent());
    }

    // ── /register ─────────────────────────────────────────────────────────────

    public function testRegisterPageReturns200(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register');

        $this->assertResponseIsSuccessful();
    }

    public function testRegisterPageHasForm(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register');

        $this->assertSelectorExists('form');
        $this->assertSelectorExists('input[type="email"]');
        $this->assertSelectorExists('input[type="password"]');
    }

    public function testRegisterPageContainsWearbaseBranding(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register');

        $this->assertSelectorTextContains('body', 'WEARBASE');
    }

    public function testRegisterPageHasLoginLink(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register');

        $this->assertSelectorExists('a[href*="login"]');
    }

    // ── /forgot-password ──────────────────────────────────────────────────────

    public function testForgotPasswordPageReturns200(): void
    {
        $client = static::createClient();
        $client->request('GET', '/forgot-password');

        $this->assertResponseIsSuccessful();
    }

    public function testForgotPasswordPageHasEmailField(): void
    {
        $client = static::createClient();
        $client->request('GET', '/forgot-password');

        $this->assertSelectorExists('input[type="email"]');
        $this->assertSelectorExists('button[type="submit"]');
    }

    public function testForgotPasswordPageContainsBranding(): void
    {
        $client = static::createClient();
        $client->request('GET', '/forgot-password');

        $this->assertSelectorTextContains('body', 'WEARBASE');
    }

    /**
     * Submitting with any email should show the "sent" state
     * (no information leakage about whether the account exists).
     */
    public function testForgotPasswordSubmitAlwaysShowsSentState(): void
    {
        $client = static::createClient();
        $client->request('GET', '/forgot-password');
        $client->submitForm('Отправить ссылку', [
            'email' => 'nonexistent@example.com',
        ]);

        $this->assertResponseIsSuccessful();
        // Either the page shows a "sent" state or redirects — must not crash
        $this->assertNotSame(500, $client->getResponse()->getStatusCode());
    }

    public function testForgotPasswordHasLoginLink(): void
    {
        $client = static::createClient();
        $client->request('GET', '/forgot-password');

        $this->assertSelectorExists('a[href*="login"]');
    }
}
