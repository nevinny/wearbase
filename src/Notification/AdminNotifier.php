<?php

declare(strict_types=1);

namespace App\Notification;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Уведомления администратору проекта в Telegram (отдельный чат/канал).
 * Если ADMIN_TELEGRAM_CHAT_ID не задан — деградирует в no-op.
 *
 * Отложенная отправка: пока в RequestStack есть запрос (HTTP), сообщения копятся и уходят
 * на kernel.terminate — Response::send() уже вызвал fastcgi_finish_request(), пользователь
 * Telegram не ждёт. Порядок сохраняется (одна очередь). В CLI запроса нет → шлём сразу
 * (кроны не теряют уведомления). На terminate запрос уже снят со стека, поэтому отправки из
 * других terminate-слушателей тоже идут сразу, а не в потерянную очередь.
 * kernel.terminate срабатывает и после 500 (HttpKernel превращает исключение в Response);
 * теряются сообщения только при фатале процесса (OOM/max_execution_time) до terminate.
 * Сбои Telegram не бросают исключений: TelegramNotifier::send логирует и возвращает false.
 */
class AdminNotifier
{
    /** @var list<array{string, array<mixed>|null}> */
    private array $queue = [];

    public function __construct(
        private readonly TelegramNotifier $telegram,
        private readonly RequestStack $requestStack,
        private readonly string $adminChatId = '',
    ) {}

    /** @param array<mixed>|null $replyMarkup */
    private function dispatch(string $html, ?array $replyMarkup = null): void
    {
        if ($this->requestStack->getMainRequest() !== null) {
            $this->queue[] = [$html, $replyMarkup];

            return;
        }
        $this->telegram->send($this->adminChatId, $html, $replyMarkup);
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function flush(): void
    {
        $queue = $this->queue;
        $this->queue = [];
        foreach ($queue as [$html, $replyMarkup]) {
            $this->telegram->send($this->adminChatId, $html, $replyMarkup);
        }
    }

    public function isEnabled(): bool
    {
        return $this->adminChatId !== '';
    }

    public function send(string $html): void
    {
        if ($this->adminChatId === '') {
            return;
        }
        $this->dispatch($html);
    }

    /**
     * Уведомление с одной inline-кнопкой-действием (callback_data обрабатывает TelegramController).
     * Напр. «🚫 Скрыть с публикации» под сообщением об опубликованном бренде.
     */
    public function sendWithButton(string $html, string $buttonText, string $callbackData): void
    {
        if ($this->adminChatId === '') {
            return;
        }
        $this->dispatch($html, [
            'inline_keyboard' => [[['text' => $buttonText, 'callback_data' => $callbackData]]],
        ]);
    }

    /**
     * Уведомление с НЕСКОЛЬКИМИ inline-кнопками (по одной на строку), напр. список
     * опубликованных брендов, у каждого «🚫 Скрыть».
     *
     * Каждая кнопка — либо callback (`data`), либо ссылка (`url`). URL-кнопки надёжнее
     * callback'ов: клик открывает подписанную ссылку в браузере, не зависит от вебхука
     * (вебхук Telegram→прод таймаутит). Дрип использует именно `url`.
     * @param list<array{text:string, data?:string, url?:string}> $buttons
     */
    public function sendWithButtons(string $html, array $buttons): void
    {
        if ($this->adminChatId === '' || $buttons === []) {
            return;
        }
        $rows = array_map(
            static function (array $b): array {
                $btn = ['text' => $b['text']];
                if (isset($b['url'])) {
                    $btn['url'] = $b['url'];
                } else {
                    $btn['callback_data'] = $b['data'] ?? '';
                }

                return [$btn];
            },
            $buttons,
        );
        $this->dispatch($html, ['inline_keyboard' => $rows]);
    }

    /** Тот ли это чат, что наш админский (защита callback'ов: чужой не должен скрывать бренды). */
    public function isAdminChat(string $chatId): bool
    {
        return $this->adminChatId !== '' && $chatId === $this->adminChatId;
    }
}
