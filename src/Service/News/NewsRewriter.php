<?php

declare(strict_types=1);

namespace App\Service\News;

use App\Enum\NewsRubric;

/**
 * Рерайт «заметка на основе фактов» (_docs/news-sources.md §2, _docs/news-sources-tos.md §2):
 * НЕ переписываем чужой текст — пишем собственную заметку только на фактах,
 * чёрный список жанров (интервью/колонки/топ-N) отсеиваем до рерайта.
 * Рубрикатор — та же LLM, один вызов возвращает title+body+rubric.
 */
final class NewsRewriter
{
    public function __construct(private readonly NewsLlmClientInterface $llm)
    {
    }

    /** Жанры, которые нельзя рерайтить: охраняется форма/составительство (ст. 1259 п. 2). */
    private const FORBIDDEN_GENRES = [
        'интервью', 'опрос', 'колонка', 'мнение', 'блог',
        'тест', 'гороскоп', 'топ-', 'рейтинг', 'подборка',
    ];

    public function isForbiddenGenre(string $title): bool
    {
        $t = mb_strtolower($title);

        foreach (self::FORBIDDEN_GENRES as $g) {
            if (str_contains($t, $g)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{title: string, body: string, rubric: NewsRubric}
     *
     * @throws \RuntimeException если модель вернула нераспознаваемый ответ
     * @throws NewsLlmUnavailableException если ollama недоступна
     */
    public function rewrite(string $sourceName, string $articleText, ?string $rubricHint = null): array
    {
        $system = <<<'TXT'
Ты редактор российского сайта о моде и гардеробе WEARBASE.
Тебе дают факты из новости другого издания. Напиши ЗАМЕТКУ НА ОСНОВЕ ФАКТОВ:
- используй ТОЛЬКО факты из исходника, никаких домыслов;
- текст полностью свой: не копируй фразы и предложения исходника;
- 3–6 абзацев простым языком, без заголовков внутри;
- не вставляй ссылки и упоминания «наш сайт»;
- ответ строго в JSON: {"title": "…", "body": "…", "rubric": "fashion|kids|wardrobe|other"}
TXT;

        $user = sprintf(
            "Издание: %s%s\n\nФакты:\n%s",
            $sourceName,
            $rubricHint !== null ? "\nОжидаемая тематика: " . $rubricHint : '',
            mb_substr($articleText, 0, 8000),
        );

        $raw = $this->llm->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], 'json');

        $parsed = json_decode($this->stripReasoning($raw), true);
        if (!is_array($parsed) || !isset($parsed['title'], $parsed['body'])) {
            throw new \RuntimeException('LLM вернула ответ не в ожидаемом JSON');
        }

        $title = trim((string) $parsed['title']);
        $body = trim((string) $parsed['body']);
        if ($title === '' || mb_strlen($body) < self::MIN_BODY_LENGTH) {
            throw new \RuntimeException('Пустой/слишком короткий ответ LLM');
        }

        return [
            'title' => $title,
            'body' => $body,
            'rubric' => NewsRubric::tryFromMixed($parsed['rubric'] ?? null),
        ];
    }

    /** qwen3 может оборачивать ответ в <think>…</think> или ```json-фенсы. */
    public function stripReasoning(string $raw): string
    {
        $s = preg_replace('~<think>.*?</think>~is', '', $raw) ?? $raw;
        $s = preg_replace('~```(?:json)?\s*|```~i', '', $s) ?? $s;

        return trim($s);
    }

    private const MIN_BODY_LENGTH = 300;
}
