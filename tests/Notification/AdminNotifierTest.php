<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Notification\AdminNotifier;
use App\Notification\TelegramNotifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class AdminNotifierTest extends TestCase
{
    /** @var list<array{string, string, ?array}> */
    private array $sent = [];

    private function telegram(): TelegramNotifier
    {
        $tg = $this->createMock(TelegramNotifier::class);
        $tg->method('send')->willReturnCallback(function (string $chat, string $text, ?array $markup = null): bool {
            $this->sent[] = [$chat, $text, $markup];

            return true;
        });

        return $tg;
    }

    public function testHttpContextDefersUntilTerminateAndKeepsOrder(): void
    {
        $stack = new RequestStack();
        $stack->push(new Request());
        $notifier = new AdminNotifier($this->telegram(), $stack, '42');

        $notifier->send('first');
        $notifier->sendWithButton('second', 'Btn', 'cb');
        $notifier->sendWithButtons('third', [['text' => 'Go', 'url' => 'https://x.y']]);
        $this->assertSame([], $this->sent, 'в HTTP до terminate ничего не уходит');

        $notifier->flush();
        $this->assertSame(['first', 'second', 'third'], array_column($this->sent, 1));
        $this->assertSame('42', $this->sent[0][0]);
        $this->assertSame('cb', $this->sent[1][2]['inline_keyboard'][0][0]['callback_data']);

        $notifier->flush();
        $this->assertCount(3, $this->sent, 'повторный flush не дублирует');
    }

    public function testCliSendsImmediately(): void
    {
        $notifier = new AdminNotifier($this->telegram(), new RequestStack(), '42');

        $notifier->send('cron message');

        $this->assertSame(['cron message'], array_column($this->sent, 1));
    }

    public function testSendAfterRequestPoppedGoesImmediately(): void
    {
        // Так выглядит kernel.terminate: запрос уже снят со стека → отправка из другого
        // terminate-слушателя не должна оседать в очереди, которую уже никто не сбросит.
        $stack = new RequestStack();
        $stack->push(new Request());
        $stack->pop();
        $notifier = new AdminNotifier($this->telegram(), $stack, '42');

        $notifier->send('late');

        $this->assertSame(['late'], array_column($this->sent, 1));
    }

    public function testDisabledWithoutChatIdIsNoop(): void
    {
        $stack = new RequestStack();
        $stack->push(new Request());
        $notifier = new AdminNotifier($this->telegram(), $stack, '');

        $notifier->send('x');
        $notifier->flush();

        $this->assertSame([], $this->sent);
    }
}
