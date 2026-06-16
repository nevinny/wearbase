<?php

namespace App\Service\Social;

use App\Entity\SocialPost;

/**
 * Подбор медиа для поста. MVP: брендовые рубрики используют уже существующий логотип бренда
 * (VichUploader, /images/logos) — без внешней генерации. Карточки текст-рубрик и Reels
 * (Cloudflare/Pollinations image-gen, Revideo) — следующий шаг, см. docs/marketing_instagram.md §5.
 *
 * Возвращает public-относительный путь к медиа или null (тогда пост текстовый —
 * ок для Telegram/VK; для Instagram канал-генератор уводит такой пост в held).
 */
class MediaRenderer
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function render(SocialPost $post): ?string
    {
        $brand = $post->getBrand();
        if ($brand === null) {
            return null; // текст-рубрика: карточка-генерация — TODO (§5)
        }

        $logo = $brand->getLogo();
        if ($logo === null || $logo === '') {
            return null;
        }

        $rel = '/images/logos/' . $logo;
        if (!is_file($this->projectDir . '/public_html' . $rel)) {
            return null; // файла нет на диске — не отдаём битый путь
        }

        return $rel;
    }
}
