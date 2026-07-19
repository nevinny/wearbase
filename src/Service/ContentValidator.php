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

    /**
     * Незаполненные скобки-плейсхолдеры от LLM: [название бренда], [город],
     * [услуги/товары], [вставьте …], а также цитатные/заглушечные [1], [N], […].
     * Раньше стоял catch-all /\[[^\]]+\]/ — он резал ЛЕГИТИМНЫЕ фонетические
     * транскрипции с сайтов брендов («РОШ[И']» у Roshi, «[эла́пс]» у Elapse Space,
     * «[ЮЛ]» у YLLL) → детерминированный false positive, 200+ generate_failed подряд.
     * Теперь скобки режутся только с шаблонным словом-маркером внутри.
     */
    private const BRACKET_PLACEHOLDER_PATTERN =
        '/\[[^\]]*(?:назван|бренд|город|стран|товар|услуг|описан|вставь|вставит|укаж|заполн|пример|текст|ссылк|brand|city|name|insert|placeholder|todo|example|description)[^\]]*\]'
        . '|\[\s*(?:\d+|[NXnx]|\.{2,3}|…)\s*\]/iu';

    private const PLACEHOLDER_PATTERNS = [
        self::BRACKET_PLACEHOLDER_PATTERN,
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
        // квалификатор «\p{L}+-либо» ловит какая-либо/какой-либо/каких-либо (была дыра: только «каких-либо»)
        '/(?:отсутству\p{L}+|не содержит|нет)\s+(?:\p{L}+-либо\s+|никак\p{L}+\s+|достаточн\p{L}+\s+)?(?:информаци\p{L}+|сведени\p{L}+|данны\p{L}+)[^.]{0,45}(?:бренд|росси|fashion|одежд|производител|марк[еи])/iu',
        // +гео/портал/туризм-сущности и зазор {0,45} (кейс Mauritius: «материалы относятся к островному государству»)
        '/(?:данные|факты|источники|материалы|сведения)[^.]{0,45}(?:относятся|описывают|касаются|посвящены)[^.]{0,45}(?:компани|сервис|другой|иностранн|маркетплейс|поисков|государств|остров|стран[аеуые]|город|регион|портал|туриз|путешеств)/iu',
        // Модель уверенно описала ЧУЖУЮ сущность и сама же оговорилась «…а не является брендом
        // одежды» (кейс Blackstone: ООО, пневмоэлементы автоподвески). Не refusal по форме, но
        // тот же смысл — публиковать нельзя. (FASHION_SIGNALS тут не спасает: «брендом одежды»
        // есть в самой фразе-отрицании.)
        '/не\s+являе\p{L}+[^.]{0,40}брендом\s+одежд/iu',
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

        if (preg_match(self::BRACKET_PLACEHOLDER_PATTERN, $description)) {
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

    /**
     * Известный дефект локальной модели gemma4:26b (кастомный мердж, docs/tasktracker.md,
     * баг «му» от 2026-07-07): слог «му»/«ло»/«лан» вклинивается НЕ В НАЧАЛЕ слова —
     * и в середине («ассортимумент», «мессмуджеры», «СДмуК»), и на конце («ассортиму»,
     * «почму», «одему» — живой пример из репро сессии, где word-final «-му» пропускался
     * старой версией паттерна). Слово-начальные «мука»/«музыка»/«мусор»/«мультибренд» —
     * легитимны по построению (искл. только по позиции 0). «ло»/«лан»/словофинальное «му»
     * — легитимные паттерны в огромном числе русских слов («около», «слово», «план»,
     * «самому», «приму», «оказалось»), поэтому этот метод отдаёт только КАНДИДАТОВ —
     * сам по себе кандидат НЕ признак брака. Финальное решение — за вызывающим кодом:
     * кандидат считается браком, только если его ЖЕ не знает словарь (Yandex Speller) —
     * двухфакторная проверка (проверено: «самому»/«приму»/«всему»/«около»/«слово» Speller
     * не флагует — двухфакторность держит точность и на word-final кандидатах).
     *
     * @return string[] слова-кандидаты (без дублей, в порядке первого появления)
     */
    public function findGlitchCandidateWords(string $text): array
    {
        if (!preg_match_all('/\p{L}+/u', $text, $matches)) {
            return [];
        }

        $candidates = [];
        foreach (array_unique($matches[0]) as $word) {
            $lower = mb_strtolower($word, 'UTF-8');
            foreach (['му', 'ло', 'лан'] as $infix) {
                $pos = mb_strpos($lower, $infix, 0, 'UTF-8');
                if ($pos !== false && $pos > 0) {
                    $candidates[] = $word;
                    break;
                }
            }
        }

        return $candidates;
    }
}