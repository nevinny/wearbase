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
        $this->assertStringContainsString('/register?brand=1', (string) $message->getHtmlBody());
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
