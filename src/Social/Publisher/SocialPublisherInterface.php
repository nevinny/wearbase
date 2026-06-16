<?php

declare(strict_types=1);

namespace App\Social\Publisher;

use App\Entity\SocialChannel;

/**
 * Публикатор в соцсеть. Каждая площадка (tg|vk|ig) — отдельная реализация,
 * резолвится в SocialPublisherRegistry по platform() (паттерн PaymentGatewayRegistry).
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
    public function publish(SocialChannel $channel, string $caption, ?string $mediaAbsPath): string;
}
