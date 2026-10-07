<?php

declare(strict_types=1);

namespace App\Service\Social;

/**
 * Совпадение кодового слова в комментарии: регистр и ё/е не важны, слово — целиком
 * (вокруг могут быть кавычки, знаки, эмодзи, другие слова; «размеры» и «безразмерный» — не совпадение).
 */
final class CommentKeyword
{
    public static function matches(string $text, string $keyword): bool
    {
        $keyword = self::normalize($keyword);
        if ($keyword === '') {
            return false;
        }

        return in_array($keyword, preg_split('/[^\p{L}\p{N}]+/u', self::normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [], true);
    }

    private static function normalize(string $s): string
    {
        return str_replace('ё', 'е', mb_strtolower(trim($s)));
    }
}
