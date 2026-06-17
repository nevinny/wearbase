<?php

declare(strict_types=1);

namespace App\Social\Publisher;

use App\Entity\SocialChannel;
use App\Entity\SocialPost;

/**
 * Публикатор в соцсеть. Каждая площадка (tg|vk|ig) — отдельная реализация,
 * резолвится в SocialPublisherRegistry по platform() (паттерн PaymentGatewayRegistry).
 * Получает весь пост и сам оформляет CTA-ссылку под площадку (TG — кликабельный текст,
 * VK — текст+URL, IG — без URL/ссылка в профиле).
 */
interface SocialPublisherInterface
{
    /** Код площадки из SocialChannel::PLATFORM_* — по нему резолвится в реестре. */
    public function platform(): string;

    /**
     * Опубликовать пост. Возвращает ID поста на площадке (для подтягивания метрик).
     * Бросает \RuntimeException при ошибке/невалидной конфигурации канала.
     *
     * @param string|null $mediaAbsPath абсолютный путь к локальному медиа (null = текстовый пост)
     */
    public function publish(SocialChannel $channel, SocialPost $post, ?string $mediaAbsPath): string;
}
