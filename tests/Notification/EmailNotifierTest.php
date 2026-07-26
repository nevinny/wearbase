<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Notification\EmailNotifier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

/**
 * From транзакционных писем.
 *
 * Регрессия 2026-07-26: From брался из ADMIN_EMAIL (nevinny@gmail.com) — RuSender отвечал
 * 404 «User Domain not found», потому что отправлять можно только с подтверждённого домена
 * mail.wearbase.ru. Ответы при этом должны приходить владельцу → Reply-To = ADMIN_EMAIL.
 */
class EmailNotifierTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    public function testFromIsVerifiedDomainAndReplyToIsAdmin(): void
    {
        self::bootKernel();
        $notifier = self::getContainer()->get(EmailNotifier::class);

        $sent = $notifier->send('owner@example.com', 'Тема', 'lead_welcome', ['brandName' => 'Бренд']);

        $this->assertTrue($sent, 'send() должен сообщать об успехе булевым результатом');
        $this->assertEmailCount(1);

        $message = $this->getMailerMessage();
        $this->assertSame('hello@mail.wearbase.ru', $message->getFrom()[0]->getAddress());
        $this->assertNotSame([], $message->getReplyTo(), 'Reply-To должен быть выставлен');
        $this->assertSame($notifier->getAdminEmail(), $message->getReplyTo()[0]->getAddress());
    }
}
