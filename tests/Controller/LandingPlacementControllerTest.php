<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\LandingLead;
use App\Notification\AdminNotifier;
use App\Notification\TelegramNotifier;
use Symfony\Component\HttpFoundation\RequestStack;

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

    public function testLeadAdminPingGoesToTelegramOnlyAfterResponseHandled(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();
        $client->disableReboot(); // иначе подмена сервиса пропадёт при втором запросе
        $container = static::getContainer();
        $stack = $container->get(RequestStack::class);

        // Фиксируем, в какой момент вызван TG-клиент: во время обработки запроса (запрос на стеке)
        // или уже на kernel.terminate (стек пуст).
        $calls = [];
        $tg = $this->createMock(TelegramNotifier::class);
        $tg->method('send')->willReturnCallback(function (string $chat, string $text) use (&$calls, $stack): bool {
            $calls[] = ['inRequest' => $stack->getMainRequest() !== null, 'text' => $text];

            return true;
        });
        $container->set(AdminNotifier::class, new AdminNotifier($tg, $stack, '42'));

        $email = 'placement-tg-' . uniqid() . '@example.com';
        $this->fixtureEmails[] = $email;
        $crawler = $client->request('GET', '/ru/for-brands/placement');
        $form = $crawler->selectButton('Оставить заявку')->form([
            'brand_name' => 'Отложенный Бренд',
            'email' => $email,
            'consent' => true,
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/ru/for-brands/placement/thanks');
        $this->assertNotEmpty($calls, 'после terminate TG-клиент вызван');
        foreach ($calls as $call) {
            $this->assertFalse($call['inRequest'], 'TG не вызывается внутри обработки запроса');
        }
        $this->assertStringContainsString('Отложенный Бренд', end($calls)['text']);
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
