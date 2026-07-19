<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\LandingLead;

/**
 * Лендинг услуги «Размещение под ключ» (/for-brands/placement, sales_offer.md §10) — страница
 * отдаёт 200 и форма заявки создаёт LandingLead + редиректит на страницу «спасибо».
 *
 * Run: php -d memory_limit=512M bin/phpunit --filter LandingPlacement
 */
class LandingPlacementControllerTest extends DatabaseDependentWebTestCase
{
    private array $fixtureEmails = [];

    protected function tearDown(): void
    {
        if ($this->fixtureEmails !== []) {
            $em = static::getContainer()->get('doctrine.orm.entity_manager');
            $repo = $em->getRepository(LandingLead::class);
            foreach ($this->fixtureEmails as $email) {
                if (($lead = $repo->findOneBy(['email' => $email])) !== null) {
                    $em->remove($lead);
                }
            }
            $em->flush();
            $this->fixtureEmails = [];
        }
        parent::tearDown();
    }

    public function testLandingPageReturns200(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();

        $client->request('GET', '/ru/for-brands/placement');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Ваша карточка уже собрана');
        $this->assertSelectorExists('form[action*="/for-brands/placement/lead"]');
    }

    public function testLeadFormCreatesLandingLeadAndRedirectsToThanks(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();

        $email = 'placement-test-' . uniqid() . '@example.com';
        $this->fixtureEmails[] = $email;

        $crawler = $client->request('GET', '/ru/for-brands/placement');
        $form = $crawler->selectButton('Оставить заявку')->form([
            'brand_name' => 'Тестовый Бренд',
            'email' => $email,
            'website' => 'https://example.com/testbrand',
            'consent' => true,
        ]);

        $client->submit($form);

        $this->assertResponseRedirects('/ru/for-brands/placement/thanks');
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Заявка принята');

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $lead = $em->getRepository(LandingLead::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($lead);
        $this->assertSame('Тестовый Бренд', $lead->getBrandName());
        $this->assertSame('for-brands-placement', $lead->getSource());
        $this->assertSame('https://example.com/testbrand', $lead->getWebsite());
    }

    public function testHoneypotFieldSilentlyDropsLead(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();

        $email = 'placement-bot-' . uniqid() . '@example.com';

        $crawler = $client->request('GET', '/ru/for-brands/placement');
        $form = $crawler->selectButton('Оставить заявку')->form([
            'brand_name' => 'Бот',
            'email' => $email,
            'company_site' => 'http://spam.example',
            'consent' => true,
        ]);

        $client->submit($form);

        $this->assertResponseRedirects('/ru/for-brands/placement/thanks');

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $lead = $em->getRepository(LandingLead::class)->findOneBy(['email' => $email]);
        $this->assertNull($lead, 'Honeypot должен тихо отклонить заявку без записи в БД');
    }
}
