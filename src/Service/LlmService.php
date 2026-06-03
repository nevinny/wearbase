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
        private readonly string $localUrl = '',
        private readonly string $localModel = '',
    ) {
    }

    /**
     * Базовый метод генерации. Принимает опциональный системный промпт.
     *
     * @param int|null $maxTokens переопределить лимит токенов (null = DEFAULT_MAX_TOKENS)
     */
    public function generate(string $prompt, ?string $systemPrompt = null, ?string $model = null, int $timeout = 120, ?int $maxTokens = null, bool $local = false, bool $think = true): string
    {
        $messages = [];
        if ($systemPrompt !== null) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $local
            ? $this->generateLocal($messages, $model ?? $this->localModel, $timeout, $think)
            : $this->generateRemote($messages, $model ?? $this->model, $timeout, $maxTokens);
    }

    /**
     * Локальный ollama — нативный /api/chat по IP.
     * $think=true (описания): num_predict безлимитный, размышления не обрезают ответ,
     * reasoning в message.thinking, чистый текст в message.content; вызов идёт минуты.
     * $think=false (meta — просто JSON): размышления не нужны, ответ за секунды.
     */
    private function generateLocal(array $messages, string $model, int $timeout, bool $think = true): string
    {
        $payload = [
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
        ];
        if (!$think) {
            $payload['think'] = false;
        }

        try {
            $response = $this->httpClient->request('POST', $this->localUrl, [
                'headers' => ['Content-Type' => 'application/json'],
                'json'    => $payload,
                'timeout' => max($timeout, 600),
            ]);

            return $response->toArray()['message']['content'] ?? '';
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Local LLM request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function generateRemote(array $messages, string $model, int $timeout, ?int $maxTokens): string
    {
        try {
            $response = $this->httpClient->request('POST', self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'      => $model,
                    'messages'   => $messages,
                    'max_tokens' => $maxTokens ?? self::DEFAULT_MAX_TOKENS,
                ],
                'timeout' => $timeout,
            ]);

            return $response->toArray()['choices'][0]['message']['content'] ?? '';
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
        ?string $facts = null,
    ): string {
        $lines = ["Бренд: {$brandName}"];
        if ($city !== null) {
            $lines[] = "Город: {$city}";
        }
        if ($style !== null) {
            $lines[] = "Стиль: {$style}";
        }
        $brandContext = implode("\n", $lines);

        // RAG: если переданы реальные факты — генерируем строго из них, без выдумок.
        // Если $facts == null — поведение остаётся прежним (модель пишет из своих знаний).
        $grounded = $facts !== null && trim($facts) !== '';

        $systemPrompt = 'Ты — копирайтер fashion-индустрии. Пишешь только на русском языке. '
            . ($grounded
                ? 'Используй ИСКЛЮЧИТЕЛЬНО факты из блока «Проверенные факты»: не добавляй данные, которых там нет. '
                : '')
            . 'Отвечаешь исключительно текстом описания, без заголовков и markdown.';

        $factsBlock = $grounded
            ? "Проверенные факты о бренде (из официальных источников):\n{$facts}\n"
            : '';

        $sourceRule = $grounded
            ? '- Опирайся ТОЛЬКО на «Проверенные факты» выше; не добавляй то, чего там нет'
            : '- Если данных о бренде нет — опиши российский streetwear-сегмент в целом';

        $prompt = <<<EOT
Напиши развёрнутое описание для российского бренда одежды.

{$brandContext}

{$factsBlock}Требования:
- Объём: НЕ МЕНЕЕ 250 слов (обязательно)
- Тон: информативный, без восторженных фраз
- Структура: 3–4 абзаца, разделённых пустой строкой
- Включи: нишу бренда, философию, особенности (при наличии данных)
- НЕ используй слова: "инновационный", "уникальный", "передовой", "лидирующий", "новаторский", "выделяется", "отличается"
- НЕ используй фразы: "мы стремимся", "наша миссия", "в современном мире"
{$sourceRule}

Формат: только текст, без заголовков и markdown-разметки.
EOT;

        return $this->generate($prompt, $systemPrompt, local: true, think: false);
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

        return $this->generate($prompt, $systemPrompt, local: true, think: false);
    }

    /**
     * SEO-данные (title, description, keywords) на основе нового описания.
     */
    public function generateBrandMeta(string $brandName, ?string $city = null, ?string $facts = null): array
    {
        $cityContext = $city ? " из города {$city}" : '';

        $prompt   = $this->buildMetaPrompt($brandName, $cityContext, $facts);
        $response = $this->generate($prompt, local: true, think: false);
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
        ?string $facts = null,
    ): array {
        $cityContext = $city ? " из города {$city}" : '';
        $factsBlock  = ($facts !== null && trim($facts) !== '')
            ? "\nДополнительные факты (для точных ключевиков):\n{$facts}\n"
            : '';

        $prompt = <<<EOT
Бренд: {$brandName}{$cityContext}

Существующее описание:
{$existingDescription}
{$factsBlock}

На основе этого описания сгенерируй SEO-данные в формате JSON:
{
  "title": "Заголовок страницы (до 60 символов)",
  "description": "Meta description (130–140 символов)",
  "keywords": "keyword1, keyword2, keyword3"
}

{$this->metaRequirements($brandName)}

Ответь ТОЛЬКО валидным JSON без markdown-разметки.
EOT;

        $response = $this->generate($prompt, local: true, think: false);
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

    private function buildMetaPrompt(string $brandName, string $cityContext, ?string $facts = null): string
    {
        $factsBlock = ($facts !== null && trim($facts) !== '')
            ? "Факты о бренде (используй для точных ключевиков, не выдумывай):\n{$facts}\n\n"
            : '';

        return <<<EOT
Для бренда {$brandName}{$cityContext} сгенерируй SEO-данные в формате JSON:
{
  "title": "Заголовок страницы (до 55 символов)",
  "description": "Meta description (130–140 символов)",
  "keywords": "keyword1, keyword2, keyword3"
}

{$factsBlock}{$this->metaRequirements($brandName)}

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

    // =========================================================================
    // Contact enrichment (uses Perplexity Sonar — web-capable model)
    // =========================================================================

    /**
     * Ищет контактные данные бренда через Perplexity Sonar (интернет-поиск).
     *
     * Модель ОБЯЗАНА уметь искать в интернете прямо сейчас — поэтому используем
     * perplexity/sonar, а не дефолтный claude, который выдаёт только обученные знания.
     *
     * Структура ответа:
     * {
     *   "website":    "https://...",
     *   "email":      "info@...",
     *   "phone":      "+7...",
     *   "instagram":  "https://instagram.com/...",
     *   "vk":         "https://vk.com/...",
     *   "telegram":   "https://t.me/...",
     *   "youtube":    "https://youtube.com/...",
     *   "stores": [
     *     {"address": "...", "city": "...", "phone": "..."}
     *   ],
     *   "confidence": "high" | "medium" | "low" | "not_found",
     *   "notes":      "краткий комментарий"
     * }
     *
     * Все незнайденные поля — null.
     * confidence = "not_found" если бренд вообще не найден в интернете.
     *
     * @throws \RuntimeException при сетевой ошибке
     */
    public function researchBrandContacts(
        string $brandName,
        ?string $city = null,
        ?string $style = null,
    ): array {
        $cityContext  = $city  ? ", город: {$city}"  : '';
        $styleContext = $style ? ", стиль: {$style}" : '';

        $prompt = <<<EOT
Найди актуальную контактную информацию для российского бренда одежды «{$brandName}»{$cityContext}{$styleContext}.

Верни ТОЛЬКО валидный JSON (без markdown-обёртки, без пояснений):
{
  "website":   "https://официальный-сайт.ru или null",
  "email":     "info@... или null",
  "phone":     "+7XXXXXXXXXX или null",
  "instagram": "https://instagram.com/... или null",
  "vk":        "https://vk.com/... или null",
  "telegram":  "https://t.me/... или null",
  "youtube":   "https://youtube.com/... или null",
  "stores": [
    {"address": "полный адрес", "city": "город", "phone": "телефон или null"}
  ],
  "confidence": "high",
  "notes": "краткий комментарий"
}

Правила:
- Незнайденные поля: null (строкой), не пропускай ключи
- stores: только реальные физические точки самого бренда (не дистрибьюторы)
- stores: пустой массив [] если магазинов не найдено
- phone: нормализованный формат +7XXXXXXXXXX (10 цифр после +7)
- confidence уровни:
  - "high"      — нашёл официальный сайт бренда
  - "medium"    — нашёл только соцсети или косвенные упоминания
  - "low"       — данные сомнительные или частично совпадают
  - "not_found" — бренд в интернете не найден вообще
- Не придумывай данные — лучше null чем выдумка
EOT;

        // 512 токенов достаточно для компактного JSON с контактами.
        // Perplexity Sonar стоит дороже обычных моделей — экономим.
        $response = $this->generate($prompt, model: 'perplexity/sonar', timeout: 60, maxTokens: 512);
        $parsed   = $this->extractJson($response);

        if ($parsed === null) {
            throw new \RuntimeException("Не удалось распарсить JSON от Perplexity для бренда «{$brandName}»");
        }

        return $this->normalizeContactsResponse($parsed);
    }

    /**
     * Извлекает контакты из УЖЕ собранного текста страниц бренда (RAG-скрейп)
     * локальной моделью — без платного Perplexity. Возвращает ТОТ ЖЕ контракт,
     * что researchBrandContacts(), поэтому applyContacts()/нормализация не меняются.
     */
    public function extractBrandContactsFromText(
        string $brandName,
        string $scrapedText,
        ?string $city = null,
    ): array {
        $cityContext = $city ? ", город: {$city}" : '';
        $text = mb_substr($scrapedText, 0, 12000);

        $prompt = <<<EOT
Из текста со страниц бренда одежды «{$brandName}»{$cityContext} извлеки контакты.
Используй ТОЛЬКО факты из текста ниже — НИЧЕГО не выдумывай. Поля, которых нет в тексте — null.

ТЕКСТ:
{$text}

Верни ТОЛЬКО валидный JSON (без markdown):
{
  "website":   "https://... или null",
  "email":     "info@... или null",
  "phone":     "+7XXXXXXXXXX или null",
  "instagram": "https://instagram.com/... или null",
  "vk":        "https://vk.com/... или null",
  "telegram":  "https://t.me/... или null",
  "youtube":   "https://youtube.com/... или null",
  "stores": [ {"address": "...", "city": "...", "phone": "... или null"} ],
  "confidence": "high|medium|low|not_found",
  "notes": "краткий комментарий"
}

Правила:
- Незнайденные поля: null (строкой), ключи не пропускай
- phone: формат +7XXXXXXXXXX; stores: [] если нет
- confidence: high — есть сайт/почта; medium — только соцсети; low — сомнительно; not_found — контактов нет
EOT;

        $response = $this->generate($prompt, local: true, think: false);
        $parsed   = $this->extractJson($response);

        if ($parsed === null) {
            throw new \RuntimeException("Не удалось распарсить JSON контактов для «{$brandName}»");
        }

        return $this->normalizeContactsResponse($parsed);
    }

    /**
     * Приводит ответ к стандартному виду — все ожидаемые ключи присутствуют.
     */
    private function normalizeContactsResponse(array $data): array
    {
        return [
            'website'    => $this->nullableString($data['website']   ?? null),
            'email'      => $this->nullableString($data['email']     ?? null),
            'phone'      => $this->nullableString($data['phone']     ?? null),
            'instagram'  => $this->nullableString($data['instagram'] ?? null),
            'vk'         => $this->nullableString($data['vk']        ?? null),
            'telegram'   => $this->nullableString($data['telegram']  ?? null),
            'youtube'    => $this->nullableString($data['youtube']   ?? null),
            'stores'     => is_array($data['stores'] ?? null) ? $data['stores'] : [],
            'confidence' => in_array($data['confidence'] ?? '', ['high', 'medium', 'low', 'not_found'], true)
                ? $data['confidence']
                : 'low',
            'notes'      => $data['notes'] ?? null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === 'null' || $value === 'N/A' || $value === '') {
            return null;
        }

        return is_string($value) ? trim($value) : null;
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
