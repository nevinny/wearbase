<?php

namespace App\Service;

/**
 * Режет текст на чанки для эмбеддинга: ~CHUNK симв. с OVERLAP перекрытием,
 * стараясь не рвать предложения. Короткий текст → один чанк.
 */
class TextChunker
{
    private const CHUNK = 1500;   // ~500 токенов RU
    private const OVERLAP = 240;

    /** @return string[] */
    public function chunk(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= self::CHUNK) {
            return [$text];
        }

        $chunks = [];
        $len = mb_strlen($text);
        $stride = self::CHUNK - self::OVERLAP;   // фиксированный шаг, без хвостового зацикливания

        for ($pos = 0; $pos < $len; $pos += $stride) {
            $piece = trim(mb_substr($text, $pos, self::CHUNK));
            if ($piece !== '') {
                $chunks[] = $piece;
            }
        }

        return $chunks;
    }
}
