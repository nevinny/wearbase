<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Подпись «быстрых» модерационных ссылок из TG-уведомлений (напр. кнопка «Скрыть»
 * под дрип-публикацией). Ссылка кликается прямо из Telegram — без логина, — но
 * подделать её нельзя: key = HMAC(action:brandId, APP_SECRET). APP_SECRET здесь
 * играет роль соли (тот же паттерн, что в ImpersonateController::sign()).
 *
 * Заменяет сломанные callback-кнопки (вебхук Telegram→прод таймаутит) на обычные
 * URL-кнопки-ссылки на прод с параметрами ?action=…&id=…&key=…
 */
class BrandActionSigner
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    /** key = хеш(action:brandId + соль). 32 hex-символа — достаточно, URL-safe. */
    public function sign(string $action, int $brandId): string
    {
        return substr(hash_hmac('sha256', $action . ':' . $brandId, $this->secret), 0, 32);
    }

    public function verify(string $action, int $brandId, string $key): bool
    {
        return $key !== '' && hash_equals($this->sign($action, $brandId), $key);
    }
}
