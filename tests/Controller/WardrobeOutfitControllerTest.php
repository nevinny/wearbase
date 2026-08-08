<?php

declare(strict_types=1);

namespace App\Tests\Controller;

class WardrobeOutfitControllerTest extends AuthenticatedWebTestCase
{
    public function testOutfitPageRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/wardrobe/outfits');

        self::assertResponseRedirects();
    }

    public function testCustomerCanOpenOutfitPage(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);
        $client->request('GET', '/account/wardrobe/outfits');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'AI-стилист');
        self::assertSelectorExists('form textarea[name="prompt"]');
    }

    public function testInvalidCsrfDoesNotCallLlm(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);
        $client->request('POST', '/account/wardrobe/outfits', ['_token' => 'wrong', 'prompt' => 'В офис']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Недействительный токен');
    }
}
