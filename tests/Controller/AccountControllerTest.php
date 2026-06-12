<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for the customer account area: /account/*
 *
 * These tests log in as a customer and verify that pages render without errors.
 * A test database is required (dbname_suffix: '_test' — see doctrine.yaml).
 *
 * Setup:
 *   php bin/console doctrine:database:create --env=test
 *   php bin/console doctrine:migrations:migrate --env=test --no-interaction
 *
 * Run with: php bin/phpunit tests/Controller/AccountControllerTest.php
 */
class AccountControllerTest extends WebTestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Test database not available. Run: bin/console doctrine:database:create --env=test');
        }
    }

    // ── Authenticated pages ───────────────────────────────────────────────────
    // Guest redirect tests live in AccessControlTest (no DB needed there).

    public function testDashboardLoadsForCustomer(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::makeCustomer());
        $client->request('GET', '/account');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'WEARBASE');
    }

    public function testDashboardHasSidebarNav(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::makeCustomer());
        $client->request('GET', '/account');

        $this->assertSelectorExists('.account-nav');
        $this->assertSelectorTextContains('.account-nav', 'Мои заказы');
        $this->assertSelectorTextContains('.account-nav', 'Профиль');
        $this->assertSelectorTextContains('.account-nav', 'Адреса');
        $this->assertSelectorTextContains('.account-nav', 'Выйти');
    }

    public function testProfilePageLoads(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::makeCustomer());
        $client->request('GET', '/account/profile');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testProfileFormHasNoConfirmPasswordField(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::makeCustomer());
        $client->request('GET', '/account/profile');

        // ProfileFormType has no confirmPassword — template must not render it
        $this->assertSelectorNotExists('[name*="confirmPassword"]');
        $this->assertSelectorNotExists('[name*="confirm_password"]');
    }

    public function testProfileFormHasPasswordField(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::makeCustomer());
        $client->request('GET', '/account/profile');

        $this->assertSelectorExists('input[type="password"]');
    }

    public function testOrdersPageLoads(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::makeCustomer());
        $client->request('GET', '/account/orders');

        $this->assertResponseIsSuccessful();
        // Either "no orders" empty state or a list of orders
        $this->assertSelectorTextContains('body', 'заказ');
    }

    public function testOrderDetailReturns404ForUnknownNumber(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::makeCustomer());
        $client->request('GET', '/account/orders/ORDER-DOES-NOT-EXIST');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testAddressesPageLoads(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::makeCustomer());
        $client->request('GET', '/account/addresses');

        $this->assertResponseIsSuccessful();
    }

    public function testNewAddressFormLoads(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::makeCustomer());
        $client->request('GET', '/account/addresses/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ── No blue background on form fields ────────────────────────────────────

    public function testProfileFormFieldsDoNotUseBgLight(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::makeCustomer());
        $crawler = $client->request('GET', '/account/profile');

        // None of the form-control inputs should carry Bootstrap's bg-light class
        $inputs = $crawler->filter('.form-control.bg-light');
        $this->assertCount(0, $inputs, 'Found .form-control.bg-light — голубой фон не должен быть на полях');
    }
}
