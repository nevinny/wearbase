<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Дымовой тест почты (app:mail:test): каждый шаблон должен отрендериться и уйти.
 * Именно рендер `TemplatedEmail` ломался 19.07–26.07.2026, а `mailer:test` его не проверял.
 */
class MailTestCommandTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->tester = new CommandTester((new Application(self::$kernel))->find('app:mail:test'));
    }

    public function testSendsSingleTemplateWithRenderedBody(): void
    {
        $this->tester->execute(['email' => 'nevinny@example.com', '--only' => 'lead_welcome']);

        $this->tester->assertCommandIsSuccessful();
        $this->assertEmailCount(1);

        $message = $this->getMailerMessage();
        $this->assertSame('nevinny@example.com', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('[ТЕСТ] lead_welcome', (string) $message->getSubject());
        $this->assertNotSame('', (string) $message->getHtmlBody(), 'Тело должно быть отрендерено');
        // Ссылки обязаны быть абсолютными на прод-домен: письма из CLI/крона рендерятся вне
        // веб-запроса, хост берётся из DEFAULT_URI. При DEFAULT_URI=http://localhost (дефолт .env)
        // лид получал письмо со ссылками в никуда — регрессия найдена 26.07.2026 в живом ящике.
        $body = (string) $message->getHtmlBody();
        $this->assertStringContainsString('https://wearbase.ru/register?brand=1', $body);
        $this->assertStringNotContainsString('localhost', $body);
    }

    public function testAllTemplatesRender(): void
    {
        $this->tester->execute(['email' => 'nevinny@example.com']);

        $this->tester->assertCommandIsSuccessful();
        foreach ($this->getMailerMessages() as $message) {
            $this->assertNotSame('', (string) $message->getHtmlBody(), 'Пустое тело: ' . $message->getSubject());
        }
        $this->assertGreaterThanOrEqual(5, count($this->getMailerMessages()));
    }

    public function testUnknownTemplateRejected(): void
    {
        $this->tester->execute(['email' => 'nevinny@example.com', '--only' => 'nope']);

        $this->assertSame(2, $this->tester->getStatusCode());
        $this->assertEmailCount(0);
    }
}
