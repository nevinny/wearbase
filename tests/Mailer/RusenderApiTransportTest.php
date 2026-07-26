<?php

declare(strict_types=1);

namespace App\Tests\Mailer;

use App\Mailer\RusenderTransportFactory;
use Symfony\Bridge\Twig\Mime\BodyRenderer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\EventListener\MessageListener;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Address;
use Twig\Environment;

/**
 * Транспорт Rusender + рендер тела письма.
 *
 * Регрессия 19.07–26.07.2026 (неделя, пока этот транспорт был активным DSN вместо отвалившегося
 * SMTP; до 19.07 письма шли через smtp.rusender.ru и рендерились штатно):
 * транспорт вызывал `parent::__construct()` без dispatcher'а,
 * поэтому `MessageEvent` не диспатчился, twig-рендерер (`BodyRenderer`) не срабатывал и любое
 * `TemplatedEmail` доходило до API без html → «A message must have a text or an HTML part».
 * Молча (soft-fail в EmailNotifier) умирали ВСЕ транзакционные письма: подтверждение email,
 * коды заявок на бренд, сброс пароля, подтверждения заказов.
 */
class RusenderApiTransportTest extends KernelTestCase
{
    public function testTemplatedEmailReachesApiWithRenderedHtml(): void
    {
        self::bootKernel();

        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? ''];

            return new MockResponse('{"uuid":"test"}', ['http_code' => 200]);
        });

        // Диспетчер с тем же слушателем, что регистрирует framework-бандл: он и рендерит тело.
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new MessageListener(
            null,
            new BodyRenderer(self::getContainer()->get(Environment::class)),
        ));

        $factory = new RusenderTransportFactory($dispatcher, $client);
        $transport = $factory->create(Dsn::fromString('rusender+api://test-key@default?key_id=42'));

        $email = (new TemplatedEmail())
            ->from(new Address('hello@mail.wearbase.ru', 'WEARBASE'))
            ->to(new Address('owner@example.com'))
            ->subject('Доступ в кабинет бренда на WEARBASE')
            ->htmlTemplate('emails/brand_access_granted.html.twig')
            ->context([
                'login' => 'owner@example.com',
                'tempPassword' => 'TempPass123',
                'brandTitle' => 'Тестовый Бренд',
                'user' => (new \App\Entity\User())->setEmail('owner@example.com'),
            ]);

        $transport->send($email);

        $this->assertCount(1, $requests, 'Транспорт должен сделать ровно один запрос к API');
        $this->assertStringContainsString('/api/v1/external-mails/send/42', $requests[0]['url']);

        $payload = json_decode((string) $requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('html', $payload['mail'], 'Тело TemplatedEmail должно быть отрендерено до отправки');
        $this->assertStringContainsString('TempPass123', $payload['mail']['html']);
        $this->assertStringContainsString('Тестовый Бренд', $payload['mail']['html']);
        $this->assertSame('owner@example.com', $payload['mail']['to']['email']);
    }

    /**
     * Дефолтный хост — beta-домен с X-Api-Key: единственная комбинация, которую RuSender
     * принимает нашим ключом (api.rusender.ru отдаёт 401, проверено с прода 2026-07-26).
     */
    public function testDefaultHostIsBetaWithApiKeyHeader(): void
    {
        self::bootKernel();

        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = ['url' => $url, 'headers' => $options['headers'] ?? []];

            return new MockResponse('{"uuid":"test"}', ['http_code' => 201]);
        });

        $factory = new RusenderTransportFactory(new EventDispatcher(), $client);
        $transport = $factory->create(Dsn::fromString('rusender+api://test-key@default'));

        $transport->send(
            (new \Symfony\Component\Mime\Email())
                ->from(new Address('hello@mail.wearbase.ru', 'WEARBASE'))
                ->to(new Address('owner@example.com'))
                ->subject('probe')
                ->html('<p>probe</p>')
        );

        $this->assertSame('https://api.beta.rusender.ru/api/v1/external-mails/send', $seen['url']);
        $this->assertContains('X-Api-Key: test-key', $seen['headers']);
    }
}
