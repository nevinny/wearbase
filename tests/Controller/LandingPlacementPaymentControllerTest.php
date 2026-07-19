<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ServicePayment;
use Symfony\Component\HttpFoundation\Response;

/**
 * Онлайн-оплата услуги «Размещение под ключ» 5 000₽ (sales_offer.md §3 TODO,
 * платформенный путь YooKassa — см. PaymentService::createServicePayment).
 *
 * Run: php -d memory_limit=512M bin/phpunit --filter LandingPlacementPayment
 */
class LandingPlacementPaymentControllerTest extends DatabaseDependentWebTestCase
{
    private array $fixtureEmails = [];

    protected function tearDown(): void
    {
        if ($this->fixtureEmails !== []) {
            $em = static::getContainer()->get('doctrine.orm.entity_manager');
            $repo = $em->getRepository(ServicePayment::class);
            foreach ($this->fixtureEmails as $email) {
                foreach ($repo->findBy(['email' => $email]) as $sp) {
                    $em->remove($sp);
                }
            }
            $em->flush();
            $this->fixtureEmails = [];
        }
        parent::tearDown();
    }

    public function testPayWithoutCsrfReturns4xx(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();

        $client->request('POST', '/ru/for-brands/placement/pay', [
            'email' => 'no-csrf-' . uniqid() . '@example.com',
        ]);

        $this->assertGreaterThanOrEqual(400, $client->getResponse()->getStatusCode());
        $this->assertLessThan(500, $client->getResponse()->getStatusCode());
    }

    public function testPayWithoutGatewayKeysGracefullyRedirectsToLeadForm(): void
    {
        // В test-окружении YOOKASSA_SHOP_ID/SECRET_KEY пусты (.env.local не грузится) —
        // PaymentService::isConfigured() === false, экшн деградирует, а не падает 500.
        $this->skipIfNoDatabase();
        $client = static::createClient();

        $email = 'pay-test-' . uniqid() . '@example.com';
        $this->fixtureEmails[] = $email;

        $crawler = $client->request('GET', '/ru/for-brands/placement');
        $form = $crawler->filter('form[action*="/for-brands/placement/pay"]')->form([
            'email' => $email,
        ]);

        $client->submit($form);

        $this->assertResponseRedirects('/ru/for-brands/placement');
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        // Флеш рендерится глобально (base.html.twig), не через локальный .form-error блок.
        $this->assertStringContainsString('оплата временно недоступна', (string) $client->getResponse()->getContent());

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $sp = $em->getRepository(ServicePayment::class)->findOneBy(['email' => $email]);
        $this->assertNull($sp, 'Без настроенного шлюза платёж не должен создаваться');
    }

    public function testPaidPageReturns200(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();

        $client->request('GET', '/ru/for-brands/placement/paid');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Оплата принята');
    }
}
