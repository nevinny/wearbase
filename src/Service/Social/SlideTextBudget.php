<?php

declare(strict_types=1);

namespace App\Service\Social;

/**
 * Пиксельный бюджет надписи на слайде (шрифт NotoSans, холст 1080 px, поля 64 px с каждой
 * стороны). Кириллица разной ширины — символьный лимит («≤22 знака») даёт ложное чувство
 * безопасности (широкие буквы вроде «Ш»/«Ж»/«Ю» переполняют строку заметно раньше лимита),
 * поэтому композер валидирует ИМ (imagettfbbox), а не количеством знаков.
 *
 * Единая точка правды: тем же методом прогоняется обязательный тест константных строк
 * композера (см. SlideScriptComposerTest) — если правка текста случайно превысит бюджет,
 * тест упадёт для ВСЕХ брендов сразу, а не для одного конкретного города/имени в проде.
 */
class SlideTextBudget
{
    /** 1080 − 2×64. */
    public const MAX_WIDTH_PX = 1080 - 2 * 64;

    public function __construct(
        private readonly string $fontPath,
    ) {
    }

    public function fits(string $line, int $fontSize): bool
    {
        $line = trim($line);
        if ($line === '') {
            return true;
        }
        if (!is_file($this->fontPath)) {
            // Шрифта нет (напр. окружение без ассетов) — не блокируем, рендер сам деградирует.
            return true;
        }

        $bbox = imagettfbbox($fontSize, 0, $this->fontPath, $line);

        return ($bbox[2] - $bbox[0]) <= self::MAX_WIDTH_PX;
    }
}
