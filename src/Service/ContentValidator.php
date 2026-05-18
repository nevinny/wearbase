<?php

namespace App\Service;

class ContentValidator
{
    private const MIN_DESCRIPTION_WORDS = 170;
    private const MAX_META_DESCRIPTION = 155;
    private const MAX_META_TITLE = 60;

    private const AI_PHRASES = [
        'инновационный',
        'инновационная',
        'инновационное',
        'инновационные',
        'уникальный',
        'уникальная',
        'уникальное',
        'уникальные',
        'передовой',
        'передовой',
        'передовые',
        'лидирующий',
        'лидирующая',
        'лидирующее',
        'новаторский',
        'новаторская',
        'выделяется',
        'отличается',
        'несравненный',
        'беспрецедентный',
    ];

    private const PLACEHOLDER_PATTERNS = [
        '/\[[^\]]+\]/',           // [услуги/товары]
        '/\{[^}]+\}/',             // {описание}
        '/placeholder/i',
        '/lorem ipsum/i',
        '/sample text/i',
        '/test data/i',
    ];

    public function validateDescription(string $description): array
    {
        $errors = [];

        if (empty(trim($description))) {
            $errors[] = 'Пустое описание';
            return $errors;
        }

        $wordCount = $this->countWords($description);
        if ($wordCount < self::MIN_DESCRIPTION_WORDS) {
            $errors[] = "Мало слов: {$wordCount} < " . self::MIN_DESCRIPTION_WORDS;
        }

        foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
            if (preg_match($pattern, $description)) {
                $errors[] = 'Найден placeholder-паттерн';
                break;
            }
        }

        if (preg_match('/https?:\/\//', $description)) {
            $errors[] = 'Содержит URL';
        }

        if (preg_match('/\[.*?\]/', $description)) {
            $errors[] = 'Содержит незаполненные квадратные скобки';
        }

        return $errors;
    }

    public function validateMeta(array $meta): array
    {
        $errors = [];

        if (!empty($meta['title'])) {
            if (mb_strlen($meta['title']) > self::MAX_META_TITLE) {
                $errors[] = 'meta_title слишком длинный: ' . mb_strlen($meta['title']) . ' > ' . self::MAX_META_TITLE;
            }
            if (preg_match('/https?:\/\//', $meta['title'])) {
                $errors[] = 'meta_title содержит URL';
            }
        }

        if (!empty($meta['description'])) {
            if (mb_strlen($meta['description']) > self::MAX_META_DESCRIPTION) {
                $errors[] = 'meta_description слишком длинный: ' . mb_strlen($meta['description']) . ' > ' . self::MAX_META_DESCRIPTION;
            }
            if (preg_match('/https?:\/\//', $meta['description'])) {
                $errors[] = 'meta_description содержит URL';
            }
        }

        if (!empty($meta['description'])) {
            foreach (self::AI_PHRASES as $phrase) {
                if (mb_stripos($meta['description'], $phrase) !== false) {
                    $errors[] = "Найдена AI-фраза: '{$phrase}'";
                    break;
                }
            }
        }

        if (!empty($meta['title'])) {
            foreach (self::AI_PHRASES as $phrase) {
                if (mb_stripos($meta['title'], $phrase) !== false) {
                    $errors[] = "meta_title содержит AI-фразу: '{$phrase}'";
                    break;
                }
            }
        }

        return $errors;
    }

    public function validateJson(string $json): array
    {
        $errors = [];

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = 'Невалидный JSON: ' . json_last_error_msg();
        }

        return $errors;
    }

    private function countWords(string $text): int
    {
        $text = strip_tags($text);
        $text = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (empty($text)) {
            return 0;
        }

        $words = explode(' ', $text);
        return count(array_filter($words, fn($w) => mb_strlen(trim($w)) > 0));
    }

    public function getAiPhrases(): array
    {
        return self::AI_PHRASES;
    }
}