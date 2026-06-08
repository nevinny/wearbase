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

    /** Детерминированный маркер отказа: модель обязана вывести его (и только его),
     *  если фактов нет / они про другую сущность. См. LlmService prompt. */
    public const REFUSAL_MARKER = 'НЕДОСТАТОЧНО_ФАКТОВ';

    /**
     * Фразы-отказы модели: LLM не смог написать описание (пустой/чужой корпус) и
     * вернул мета-текст вместо контента. По объёму/плейсхолдерам он проходит, но это
     * мусор — публиковать нельзя (инцидент Majestic: корпус про majestic.com, не .store).
     * Высокоточные anchored-паттерны (привязка к задаче/бренду/чужой сущности), чтобы
     * НЕ ловить легит-фразы вроде «отсутствует информация о размерах». Маркер выше —
     * основной путь; эти паттерны — сеть для уже-сгенерированного и legacy.
     */
    private const REFUSAL_PATTERNS = [
        '/невозможно\s+(?:составить|создать|сформировать|написать|подготовить|выполнить)/iu',
        '/не\s+может\s+быть\s+(?:выполнен|составлен|подготовлен)/iu',
        '/на основе предоставленных(?: проверенных)? фактов\s+(?:невозможно|нельзя|не\s)/iu',
        '/(?:отсутству\p{L}+|не содержит|нет)\s+(?:никакой\s+|каких-либо\s+|достаточной\s+)?(?:информаци\p{L}+|сведени\p{L}+|данны\p{L}+)[^.]{0,45}(?:бренд|росси|fashion|одежд|производител|марк[еи])/iu',
        '/(?:данные|факты|источники|материалы|сведения)[^.]{0,30}(?:относятся|описывают|касаются|посвящены)[^.]{0,30}(?:компани|сервис|другой|иностранн|маркетплейс|поисков)/iu',
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

        if (mb_stripos($description, self::REFUSAL_MARKER) !== false) {
            $errors[] = 'Маркер отказа модели — фактов нет/чужой корпус';
        } else {
            foreach (self::REFUSAL_PATTERNS as $pattern) {
                if (preg_match($pattern, $description)) {
                    $errors[] = 'Текст-отказ модели (нет/чужой корпус) — не описание бренда';
                    break;
                }
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

    /** Описание — это отказ модели (маркер или anchored-фраза)? Для роутинга в review. */
    public function isRefusal(string $description): bool
    {
        if (mb_stripos($description, self::REFUSAL_MARKER) !== false) {
            return true;
        }
        foreach (self::REFUSAL_PATTERNS as $pattern) {
            if (preg_match($pattern, $description)) {
                return true;
            }
        }

        return false;
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