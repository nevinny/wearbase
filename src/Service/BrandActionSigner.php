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

    /**
     * key = хеш(action:brandId[:exp] + соль). 32 hex-символа — достаточно, URL-safe.
     * $exp (unix timestamp) — опциональный TTL: подмешивается в подпись, поэтому его
     * нельзя подделать отдельно от key. Без $exp — бессрочная ссылка (как раньше,
     * обратная совместимость с уже разосланными ссылками «🚫 Скрыть» в дрипе).
     */
    public function sign(string $action, int $brandId, ?int $exp = null): string
    {
        $payload = $action . ':' . $brandId . ($exp !== null ? ':' . $exp : '');

        return substr(hash_hmac('sha256', $payload, $this->secret), 0, 32);
    }

    /** $exp — то же значение, что передавалось в sign(); истёкшая ссылка (time() > exp) невалидна. */
    public function verify(string $action, int $brandId, string $key, ?int $exp = null): bool
    {
        if ($exp !== null && time() > $exp) {
            return false;
        }

        return $key !== '' && hash_equals($this->sign($action, $brandId, $exp), $key);
    }
}
