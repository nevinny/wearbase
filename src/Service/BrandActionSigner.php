<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Подпись «быстрых» модерационных ссылок из TG-уведомлений (напр. кнопка «Скрыть»
 * под дрип-публикацией). Ссылка кликается прямо из Telegram — без логина, — но
 * подделать её нельзя: key = HMAC(action:brandId, соль).
 *
 * Заменяет сломанные callback-кнопки (вебхук Telegram→прод таймаутит) на обычные
 * URL-кнопки-ссылки на прод с параметрами ?action=…&id=…&key=…
 *
 * ⚠️ Соль — `AGENT_API_SECRET`, а НЕ `kernel.secret`: ссылки подписываются на Mac
 * (`app:brand:moderate-tick`), а проверяются на проде, т.е. соль обязана быть общей
 * для обоих хостов. `APP_SECRET` на Mac пуст при непустом на проде → подпись бы не
 * сходилась и кнопки молча не работали. `AGENT_API_SECRET` уже общий (он же держит
 * HMAC агент-API). Пустая соль = fail-closed: подписать нельзя, проверка не проходит.
 */
class BrandActionSigner
{
    public function __construct(
        #[Autowire('%env(default::AGENT_API_SECRET)%')]
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
        if ($this->secret === '') {
            throw new \LogicException('AGENT_API_SECRET пуст — подписать модерационную ссылку нечем.');
        }

        $payload = $action . ':' . $brandId . ($exp !== null ? ':' . $exp : '');

        return substr(hash_hmac('sha256', $payload, $this->secret), 0, 32);
    }

    /** $exp — то же значение, что передавалось в sign(); истёкшая ссылка (time() > exp) невалидна. */
    public function verify(string $action, int $brandId, string $key, ?int $exp = null): bool
    {
        if ($this->secret === '' || $key === '') {
            return false;
        }

        if ($exp !== null && time() > $exp) {
            return false;
        }

        return hash_equals($this->sign($action, $brandId, $exp), $key);
    }
}
