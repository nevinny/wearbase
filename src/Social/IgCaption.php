<?php

declare(strict_types=1);

namespace App\Social;

/** Сборка IG-подписи — общая для InstagramPublisher (локально) и handoff в castbase. */
final class IgCaption
{
    /**
     * Строка «{ctaLabel} — ссылка в профиле» встаёт ПЕРЕД абзацем хэштегов, не после: в ленте IG
     * длинная подпись сворачивается по высоте, и абзац после хэштегов (последний в подписи)
     * почти никогда не попадает в развёрнутый вид. Абзацы разделены пустой строкой (CaptionGenerator),
     * хэштеги — последний абзац, начинающийся с '#'. Нет хэштегов — строка уходит в конец.
     */
    public static function withCtaLine(string $caption, ?string $ctaLabel): string
    {
        if ($ctaLabel === null || trim($ctaLabel) === '') {
            return $caption;
        }
        $ctaLine = $ctaLabel . ' — ссылка в профиле';

        $paragraphs = explode("\n\n", $caption);
        $hashtagIndex = null;
        foreach ($paragraphs as $i => $paragraph) {
            if (str_starts_with(ltrim($paragraph), '#')) {
                $hashtagIndex = $i;
            }
        }

        if ($hashtagIndex === null) {
            $paragraphs[] = $ctaLine;
        } else {
            array_splice($paragraphs, $hashtagIndex, 0, [$ctaLine]);
        }

        return implode("\n\n", $paragraphs);
    }
}
