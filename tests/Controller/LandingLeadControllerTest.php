<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\LandingLead;

/**
 * Форма сбора лидов `landing_lead` (POST /landing/lead) — общая для трёх лендингов.
 * На `for-brands` она спрашивает название бренда и ссылку (квалификация, sales_offer.md §11),
 * на no-marketplace / marketplace-fees — по-прежнему только email.
 *
 * Run: php -d memory_limit=512M bin/phpunit --filter LandingLead
 */
class LandingLeadControllerTest extends DatabaseDependentWebTestCase
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

    public function testForBrandsFormAsksBrandNameAndWebsite(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();

        $crawler = $client->request('GET', '/ru/for-brands');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[action*="/landing/lead"] input[name="brand_name"][required]');
        $this->assertSelectorExists('form[action*="/landing/lead"] input[name="website"]');
        $this->assertCount(1, $crawler->filter('form[action*="/landing/lead"] input[name="source"][value="for-brands"]'));
    }

    public function testForBrandsLeadStoresBrandNameAndWebsite(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();

        $email = 'lead-brand-' . uniqid() . '@example.com';
        $this->fixtureEmails[] = $email;

        $crawler = $client->request('GET', '/ru/for-brands');
        $client->submit($crawler->filter('form[action*="/landing/lead"]')->form([
            'brand_name' => 'Тестовый Бренд',
            'email' => $email,
            'website' => 'instagram.com/testbrand',
        ]));

        $this->assertResponseStatusCodeSame(302);

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $lead = $em->getRepository(LandingLead::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($lead);
        $this->assertSame('for-brands', $lead->getSource());
        $this->assertSame('Тестовый Бренд', $lead->getBrandName());
        $this->assertSame('instagram.com/testbrand', $lead->getWebsite());
    }

    public function testEmailOnlyLeadStillAccepted(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();

        $email = 'lead-plain-' . uniqid() . '@example.com';
        $this->fixtureEmails[] = $email;

        $crawler = $client->request('GET', '/ru/without-marketplaces');
        $client->submit($crawler->filter('form[action*="/landing/lead"]')->form(['email' => $email]));

        $this->assertResponseStatusCodeSame(302);

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $lead = $em->getRepository(LandingLead::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($lead);
        $this->assertSame('no-marketplace', $lead->getSource());
        $this->assertNull($lead->getBrandName());
    }

    public function testRepeatLeadFillsMissingBrandName(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();

        $email = 'lead-repeat-' . uniqid() . '@example.com';
        $this->fixtureEmails[] = $email;

        $crawler = $client->request('GET', '/ru/without-marketplaces');
        $client->submit($crawler->filter('form[action*="/landing/lead"]')->form(['email' => $email]));

        $crawler = $client->request('GET', '/ru/for-brands');
        $client->submit($crawler->filter('form[action*="/landing/lead"]')->form([
            'brand_name' => 'Дозаполненный Бренд',
            'email' => $email,
            'website' => 'example.com',
        ]));

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();
        $leads = $em->getRepository(LandingLead::class)->findBy(['email' => $email]);
        $this->assertCount(1, $leads, 'Повторная заявка не должна создавать второй лид');
        $this->assertSame('Дозаполненный Бренд', $leads[0]->getBrandName());
        $this->assertSame('example.com', $leads[0]->getWebsite());
    }
}
