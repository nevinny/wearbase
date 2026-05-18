<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class LlmService
{
    private const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';
    private const DEFAULT_MAX_TOKENS = 1024;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $model = 'anthropic/claude-3.5-haiku',
    ) {
    }

    /**
     * Базовый метод генерации. Принимает опциональный системный промпт.
     */
    public function generate(string $prompt, ?string $systemPrompt = null, ?string $model = null, int $timeout = 120): string
    {
        $model = $model ?? $this->model;

        $messages = [];
        if ($systemPrompt !== null) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        try {
            $response = $this->httpClient->request('POST', self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'      => $model,
                    'messages'   => $messages,
                    'max_tokens' => self::DEFAULT_MAX_TOKENS,
                ],
                'timeout' => $timeout,
            ]);

            $data = $response->toArray();

            return $data['choices'][0]['message']['content'] ?? '';
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('LLM request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Развёрнутое описание бренда (250+ слов).
     */
    public function generateBrandDescription(
        string $brandName,
        ?string $city = null,
        ?string $style = null,
    ): string {
        $lines = ["Бренд: {$brandName}"];
        if ($city !== null) {
            $lines[] = "Город: {$city}";
        }
        if ($style !== null) {
            $lines[] = "Стиль: {$style}";
        }
        $context = implode("\n", $lines);

        $systemPrompt = 'Ты — копирайтер fashion-индустрии. Пишешь только на русском языке. '
            . 'Отвечаешь исключительно текстом описания, без заголовков и markdown.';

        $prompt = <<<EOT
Напиши развёрнутое описание для российского бренда одежды.

{$context}

Требования:
- Объём: НЕ МЕНЕЕ 250 слов (обязательно)
- Тон: информативный, без восторженных фраз
- Структура: 3–4 абзаца, разделённых пустой строкой
- Включи: нишу бренда, философию, особенности (при наличии данных)
- НЕ используй слова: "инновационный", "уникальный", "передовой", "лидирующий", "новаторский", "выделяется", "отличается"
- НЕ используй фразы: "мы стремимся", "наша миссия", "в современном мире"
- Если данных о бренде нет — опиши российский streetwear-сегмент в целом

Формат: только текст, без заголовков и markdown-разметки.
EOT;

        return $this->generate($prompt, $systemPrompt);
    }

    /**
     * Краткий анонс бренда (1–2 предложения, до 50 слов).
     */
    public function generateBrandAnons(string $brandName, ?string $city = null): string
    {
        $cityContext = $city ? " из города {$city}" : '';

        $systemPrompt = 'Ты — копирайтер. Пишешь только на русском языке. '
            . 'Отвечаешь исключительно текстом анонса, без кавычек и markdown.';

        $prompt = <<<EOT
Напиши краткий анонс (1–2 предложения, максимум 50 слов) для бренда одежды «{$brandName}»{$cityContext}.

Требования:
- Тон: нейтральный, информативный
- Укажи, чем занимается бренд
- Без кавычек и markdown

Формат: только текст.
EOT;

        return $this->generate($prompt, $systemPrompt);
    }

    /**
     * SEO-данные (title, description, keywords) на основе нового описания.
     */
    public function generateBrandMeta(string $brandName, ?string $city = null): array
    {
        $cityContext = $city ? " из города {$city}" : '';

        $prompt   = $this->buildMetaPrompt($brandName, $cityContext);
        $response = $this->generate($prompt);
        $meta     = $this->extractJson($response) ?? $this->fallbackMeta($brandName);

        return $this->normalizeMeta($meta);
    }

    /**
     * SEO-данные на основе уже существующего описания.
     */
    public function generateMetaFromExistingDescription(
        string $brandName,
        string $existingDescription,
        ?string $city = null,
    ): array {
        $cityContext = $city ? " из города {$city}" : '';

        $prompt = <<<EOT
Бренд: {$brandName}{$cityContext}

Существующее описание:
{$existingDescription}

На основе этого описания сгенерируй SEO-данные в формате JSON:
{
  "title": "Заголовок страницы (до 60 символов)",
  "description": "Meta description (130–140 символов)",
  "keywords": "keyword1, keyword2, keyword3"
}

{$this->metaRequirements($brandName)}

Ответь ТОЛЬКО валидным JSON без markdown-разметки.
EOT;

        $response = $this->generate($prompt);
        $meta     = $this->extractJson($response) ?? $this->fallbackMeta($brandName, $existingDescription);

        return $this->normalizeMeta($meta);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Программно приводит поля meta к допустимым лимитам по границам слов.
     * LLM стабильно промахивается по длине — доверяем коду, не промпту.
     */
    private function normalizeMeta(array $meta): array
    {
        return [
            'title'       => $this->truncateAtWord($meta['title'] ?? '', 60),
            'description' => $this->truncateAtWord($meta['description'] ?? '', 155),
            'keywords'    => mb_substr($meta['keywords'] ?? '', 0, 200),
        ];
    }

    /**
     * Обрезает строку до $maxLength символов по границе слова.
     * Если пробела нет — режет жёстко (крайний случай для очень длинных слов).
     */
    private function truncateAtWord(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $maxLength);
        $lastSpace = mb_strrpos($truncated, ' ');

        return $lastSpace !== false
            ? mb_substr($truncated, 0, $lastSpace)
            : $truncated;
    }

    private function buildMetaPrompt(string $brandName, string $cityContext): string
    {
        return <<<EOT
Для бренда {$brandName}{$cityContext} сгенерируй SEO-данные в формате JSON:
{
  "title": "Заголовок страницы (до 55 символов)",
  "description": "Meta description (130–140 символов)",
  "keywords": "keyword1, keyword2, keyword3"
}

{$this->metaRequirements($brandName)}

Ответь ТОЛЬКО валидным JSON без markdown-разметки.
EOT;
    }

    private function metaRequirements(string $brandName): string
    {
        return <<<EOT
Требования:
- title: ВСЕГДА включает название бренда "{$brandName}" + "бренд одежды" или "одежда"; строго до 55 символов
- description: ровно 130–140 символов, заканчивай на целом слове
- keywords: 3–5 ключевых слов через запятую
- НЕ используй слова: "уникальный", "инновационный", "передовой", "лидирующий", "новаторский", "выделяется", "отличается"
EOT;
    }

    private function extractJson(string $response): ?array
    {
        // Убираем markdown-обёртку, если модель всё же её добавила
        $cleaned = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $response);

        if (preg_match('/\{[\s\S]*\}/', $cleaned ?? $response, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }

    private function fallbackMeta(string $brandName, ?string $description = null): array
    {
        return [
            'title'       => "{$brandName} — бренд одежды | WEARBASE",
            'description' => $description
                ? mb_substr($description, 0, 155)
                : "Каталог одежды бренда {$brandName} в WEARBASE",
            'keywords'    => "{$brandName}, бренд одежды, российский бренд",
        ];
    }
}
