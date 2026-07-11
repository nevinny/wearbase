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
    public function generate(string $prompt, ?string $systemPrompt = null, ?string $model = null, int $timeout = 120, ?int $maxTokens = null, bool $local = false, bool $think = true, ?float $temperature = null): string
    {
        $messages = [];
        if ($systemPrompt !== null) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $local
            ? $this->generateLocal($messages, $model ?? $this->localModel, $timeout, $think, $temperature)
            : $this->generateRemote($messages, $model ?? $this->model, $timeout, $maxTokens);
    }

    /**
     * Локальный ollama — нативный /api/chat по IP.
     * $think=true (описания): num_predict безлимитный, размышления не обрезают ответ,
     * reasoning в message.thinking, чистый текст в message.content; вызов идёт минуты.
     * $think=false (meta — просто JSON): размышления не нужны, ответ за секунды.
     */
    private function generateLocal(array $messages, string $model, int $timeout, bool $think = true, ?float $temperature = null): string
    {
        $payload = [
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
        ];
        if (!$think) {
            $payload['think'] = false;
        }
        if ($temperature !== null) {
            $payload['options'] = ['temperature' => $temperature];
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
     * Мультимодальная генерация (vision): фото + текстовый промпт. Отдельно от
     * generate() — другой формат сообщений (content-массив remote / images[] local).
     * Модель по умолчанию — та же, что у текстовой генерации ($this->model /
     * $this->localModel); вызывающий обычно передаёт $model явно (vision-модель
     * может отличаться от основной текстовой).
     *
     * @param string[] $imagePaths локальные пути к файлам изображений
     */
    public function generateVision(string $prompt, array $imagePaths, ?string $model = null, bool $local = false): string
    {
        return $local
            ? $this->generateVisionLocal($prompt, $imagePaths, $model ?? $this->localModel)
            : $this->generateVisionRemote($prompt, $imagePaths, $model ?? $this->model);
    }

    private function generateVisionRemote(string $prompt, array $imagePaths, string $model): string
    {
        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($imagePaths as $path) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $this->imageToDataUrl($path)]];
        }

        try {
            $response = $this->httpClient->request('POST', self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'      => $model,
                    'messages'   => [['role' => 'user', 'content' => $content]],
                    'max_tokens' => self::DEFAULT_MAX_TOKENS,
                ],
                'timeout' => 60,
            ]);

            return $response->toArray()['choices'][0]['message']['content'] ?? '';
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('LLM vision request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function generateVisionLocal(string $prompt, array $imagePaths, string $model): string
    {
        $images = array_map(
            static fn(string $path): string => base64_encode((string) file_get_contents($path)),
            $imagePaths,
        );

        try {
            $response = $this->httpClient->request('POST', $this->localUrl, [
                'headers' => ['Content-Type' => 'application/json'],
                'json'    => [
                    'model'    => $model,
                    'messages' => [['role' => 'user', 'content' => $prompt, 'images' => $images]],
                    'stream'   => false,
                    'think'    => false,
                ],
                'timeout' => 120,
            ]);

            return $response->toArray()['message']['content'] ?? '';
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Local vision LLM request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function imageToDataUrl(string $path): string
    {
        $bytes = $this->downscaleImage($path);
        if ($bytes !== null) {
            return 'data:image/jpeg;base64,' . base64_encode($bytes);
        }

        $mime = mime_content_type($path) ?: 'image/jpeg';
        $data = base64_encode((string) file_get_contents($path));

        return "data:{$mime};base64,{$data}";
    }

    /**
     * Фото с телефона (3-12 МБ) в base64 не пролезают в vision-запрос по таймауту —
     * ужимаем до 1024px по длинной стороне. null = GD не справился, шлём оригинал.
     */
    private function downscaleImage(string $path, int $maxSide = 1024, int $quality = 82): ?string
    {
        if (!\function_exists('imagecreatefromstring')) {
            return null;
        }

        $raw = (string) file_get_contents($path);
        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, $maxSide / max($w, $h));
        if ($scale < 1.0) {
            $src = imagescale($src, (int) round($w * $scale), (int) round($h * $scale), IMG_BICUBIC) ?: $src;
        }

        ob_start();
        imagejpeg($src, null, $quality);

        return ob_get_clean() ?: null;
    }

    /**
     * Развёрнутое описание бренда (250+ слов).
     */
    public function generateBrandDescription(
        string $brandName,
        ?string $city = null,
        ?string $style = null,
        ?string $facts = null,
        ?string $keywords = null,
        ?string $model = null,
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

        // Детерминированный отказ: если факты про другую сущность/недостаточны — модель
        // выводит маркер вместо «вежливого» абзаца-отказа (его ловит ContentValidator).
        $refusalRule = $grounded
            ? "\n- ВАЖНО: если «Проверенные факты» относятся к ДРУГОЙ компании/сервису (не к этому бренду одежды) "
                . 'или их недостаточно для описания — выведи РОВНО одну строку «' . ContentValidator::REFUSAL_MARKER
                . '» и больше НИЧЕГО. Не объясняй, не извиняйся, не выдумывай факты'
            : '';

        // SEO (A): естественно вплести реальные поисковые запросы (Wordstat), БЕЗ переспама.
        $kwRule = ($keywords !== null && trim($keywords) !== '')
            ? "\n- SEO: естественно и органично вплети 2–4 из этих поисковых запросов (НЕ перечисляй списком, НЕ переспамь): {$keywords}"
            : '';

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
{$sourceRule}{$refusalRule}{$kwRule}

Формат: только текст, без заголовков и markdown-разметки.
EOT;

        return $this->generate($prompt, $systemPrompt, model: $model, local: true, think: false);
    }

    /**
     * Краткий анонс бренда (1–2 предложения, до 50 слов).
     */
    public function generateBrandAnons(string $brandName, ?string $city = null, ?string $description = null): string
    {
        $cityContext = $city ? " из города {$city}" : '';

        $systemPrompt = 'Ты — копирайтер. Пишешь только на русском языке. '
            . 'Отвечаешь исключительно текстом анонса, без кавычек и markdown.';

        // Grounded: при наличии описания — выжимка из него (не выдумываем заново).
        if ($description !== null && trim($description) !== '') {
            $src = mb_substr(trim($description), 0, 2000);
            $prompt = <<<EOT
Вот описание бренда одежды «{$brandName}»{$cityContext}:

{$src}

Сожми его в краткий анонс: 1–2 предложения, максимум 45 слов, суть бренда.
Только факты из описания, без кавычек и markdown. Формат: только текст.
EOT;
        } else {
            $prompt = <<<EOT
Напиши краткий анонс (1–2 предложения, максимум 50 слов) для бренда одежды «{$brandName}»{$cityContext}.

Требования:
- Тон: нейтральный, информативный
- Укажи, чем занимается бренд
- Без кавычек и markdown

Формат: только текст.
EOT;
        }

        return trim($this->generate($prompt, $systemPrompt, local: true, think: false));
    }

    /**
     * SEO-данные (title, description, keywords) на основе нового описания.
     */
    public function generateBrandMeta(string $brandName, ?string $city = null, ?string $facts = null, ?string $keywords = null): array
    {
        $cityContext = $city ? " из города {$city}" : '';

        $prompt   = $this->buildMetaPrompt($brandName, $cityContext, $facts, $keywords);
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
        ?string $keywords = null,
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

{$this->metaRequirements($brandName, $keywords)}

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
            'title'       => $this->truncateAtWord($meta['title'] ?? '', 48),
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

    private function buildMetaPrompt(string $brandName, string $cityContext, ?string $facts = null, ?string $keywords = null): string
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

{$factsBlock}{$this->metaRequirements($brandName, $keywords)}

Ответь ТОЛЬКО валидным JSON без markdown-разметки.
EOT;
    }

    private function metaRequirements(string $brandName, ?string $keywords = null): string
    {
        // SEO (B): title тянется к самому частотному реальному запросу (первый в списке).
        $titleSeo = ($keywords !== null && trim($keywords) !== '')
            ? "\n- title: по возможности органично включи главный поисковый запрос (первый из: {$keywords}), если влезает в лимит и звучит естественно"
            : '';

        return <<<EOT
Требования:
- title: ВСЕГДА включает название бренда "{$brandName}" + "бренд одежды" или "одежда"; строго до 55 символов{$titleSeo}
- description: ровно 130–140 символов, заканчивай на целом слове
- keywords: 3–5 ключевых слов через запятую
- НЕ используй слова: "уникальный", "инновационный", "передовой", "лидирующий", "новаторский", "выделяется", "отличается"
EOT;
    }

    // =========================================================================
    // Contact enrichment (локальная модель по скрейп-тексту; Perplexity удалён 2026-06-04)
    // =========================================================================

    /**
     * Извлекает контакты из УЖЕ собранного текста страниц бренда (RAG-скрейп)
     * локальной моделью — без платного Perplexity. Возвращает ТОТ ЖЕ контракт,
     * что был у Perplexity-пути (удалён 2026-06-04) — applyContacts()/нормализация не менялись.
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
     * Структурное извлечение контактов из УЖЕ собранного RAG-контекста (строка,
     * возвращённая BrandRagService::retrieve()). Промпт легче, чем
     * extractBrandContactsFromText (там скрейп-текст может быть мусорным).
     * Используется в app:contacts:refresh.
     *
     * @return array{email:?string,phone:?string,address:?string,social:list<string>}
     */
    public function extractContactsFromContext(string $brandName, string $context): array
    {
        $trimmed = mb_substr($context, 0, 8000);

        $prompt = <<<EOT
    Из РАЗНЫХ источников (факты о бренде одежды «{$brandName}») извлеки контактные данные.
    Используй ТОЛЬКО то, что написано в фактах — НИЧЕГО не выдумывай.
    Если каких-то данных нет — ставь null или пустой массив.

    ФАКТЫ:
    {$trimmed}

    Верни ТОЛЬКО валидный JSON (без markdown, без пояснений):
    {
      "email":   "info@example.com или null",
      "phone":   "+7XXXXXXXXXX или null",
      "address": "полный адрес или null",
      "social":  ["https://vk.com/...", "https://t.me/..."]
    }

    Правила:
    - email: только если явно написано «@»; «@telodvigeniia» — это соцсеть, не email
    - phone: только полные номера с кодом страны
    - social: полные URL (https://...) или null-массив; упоминания @никнейм — не ссылка, не включай
    - Поля, которых нет в фактах — null/null
    EOT;

        $response = $this->generate($prompt, local: true, think: false, timeout: 120);
        $d = $this->extractJson($response) ?? [];

        $social = [];
        foreach (($d['social'] ?? []) as $s) {
            $s = is_string($s) ? trim($s) : '';
            if ($s !== '' && filter_var($s, FILTER_VALIDATE_URL)) {
                $social[] = $s;
            }
        }

        return [
            'email'   => $this->nullableString($d['email'] ?? null),
            'phone'   => $this->nullableString($d['phone'] ?? null),
            'address' => $this->nullableString($d['address'] ?? null),
            'social'  => $social,
        ];
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

    /**
     * FAQ из поисковых фраз (SEO задача C): превращает фразы реального спроса в
     * вопросы и отвечает СТРОГО из фактов (RAG-корпус + описание бренда).
     * Grounded-принцип: нет факта для ответа — модель обязана пропустить вопрос.
     *
     * @param string[] $phrases вопросные/длиннохвостые фразы Wordstat
     * @return array<int,array{question:string,answer:string}> 0..N пар (может быть пусто)
     */
    public function generateBrandFaq(string $brandName, array $phrases, string $facts, ?string $city = null): array
    {
        $cityContext = $city ? " из города {$city}" : '';
        $phrasesList = implode("\n", array_map(static fn(string $p) => "- {$p}", $phrases));

        $prompt = <<<EOT
Бренд одежды: {$brandName}{$cityContext}

ФАКТЫ о бренде (единственный источник истины):
{$facts}

Поисковые фразы реальных пользователей (Яндекс Wordstat):
{$phrasesList}

Составь FAQ для страницы бренда. Для каждой фразы сформулируй естественный вопрос
от лица покупателя (сохрани интент фразы) и ответь на него.

ЖЁСТКИЕ ПРАВИЛА:
- Отвечай ТОЛЬКО на основе ФАКТОВ выше. НИЧЕГО не выдумывай.
- Если в фактах НЕТ информации для ответа на фразу — ПРОПУСТИ её (не включай в результат).
- Ответ: 2–4 предложения, конкретный, без воды и рекламных штампов.
- Не дублируй вопросы с одинаковым смыслом — оставь один.
- 3–6 пар максимум.

Формат ответа — ТОЛЬКО валидный JSON без markdown:
{"faq": [{"question": "...", "answer": "..."}]}
EOT;

        $response = $this->generate($prompt, local: true, think: false, timeout: 180);
        $decoded  = $this->extractJson($response);

        $out = [];
        foreach (($decoded['faq'] ?? []) as $item) {
            $q = trim((string) ($item['question'] ?? ''));
            $a = trim((string) ($item['answer'] ?? ''));
            if ($q !== '' && $a !== '' && mb_strlen($q) <= 500) {
                $out[] = ['question' => $q, 'answer' => $a];
            }
        }

        return array_slice($out, 0, 6);
    }

    /**
     * SEO Boost / GEO: статья-рейтинг «ТОП-N лучших брендов в нише».
     * Бренды переданы в порядке мест ($brands[0] — место №1). Каждый бренд
     * описывается СТРОГО из своих фактов (grounded) — без выдуманных цен, дат и
     * сравнений. Голос и формат задают $persona (автор) и $platformTone (площадка).
     *
     * @param array<int,array{name:string,city:?string,facts:string}> $brands упорядочены по местам
     * @return string markdown-тело статьи (intro + секции брендов + вывод); H1 и JSON-LD строит вызывающий
     */
    public function generateListicle(
        string $nicheTitle,
        array $brands,
        string $persona,
        string $platformTone,
        ?string $keywords = null,
        ?string $fixHint = null,
        ?float $temperature = null,
        bool $noTables = false, // Дзен не отображает таблицы — сравнение списком
    ): string {
        $n = count($brands);

        $blocks = [];
        foreach ($brands as $i => $b) {
            $place = $i + 1;
            $city  = ($b['city'] ?? null) ? " (город: {$b['city']})" : '';
            $facts = trim($b['facts']) !== ''
                ? trim($b['facts'])
                : '(фактов мало — опиши коротко и нейтрально, НИЧЕГО не выдумывая)';
            $blocks[] = "МЕСТО {$place} — {$b['name']}{$city}:\nФАКТЫ:\n{$facts}";
        }
        $brandsBlock = implode("\n\n", $blocks);

        $kwRule = ($keywords !== null && trim($keywords) !== '')
            ? "\n- Темы реального спроса (раскрой их естественным текстом по смыслу, но НЕ вставляй эти фразы дословно и НЕ выделяй жирным): {$keywords}"
            : '';

        $comparisonRule = $this->comparisonBlockRule($noTables);

        $systemPrompt = "Ты — {$persona}. Пишешь обзорную статью-рейтинг на русском языке. "
            . "Тон и формат: {$platformTone}. "
            . 'Используй ИСКЛЮЧИТЕЛЬНО факты из блока каждого бренда — не добавляй данные, которых там нет. '
            . 'Отвечаешь только markdown-текстом статьи, без обёртки ```.';

        $prompt = <<<EOT
Напиши статью-рейтинг «ТОП-{$n} брендов в категории {$nicheTitle}».

Бренды уже расставлены по местам — СОХРАНИ порядок (место 1 идёт первым и подаётся как лучший выбор):

{$brandsBlock}

Требования:
- ОБЪЁМ: 1300–2000 слов (это важно для SEO). Раскрывай каждый бренд подробно.
- НАЧНИ с блока «## Коротко»: 40–60 слов, прямой самодостаточный ответ на запрос (какие бренды в топе и как выбрать), понятный БЕЗ чтения остального — для AI Overview/сниппета.
{$comparisonRule}
- Вступление: 2–3 абзаца — зачем рейтинг и как выбирали, без воды.
- Для каждого бренда секция: заголовок «## {место}. {название}», затем 3–4 абзаца СТРОГО по его фактам (ассортимент, особенности, материалы, для кого, ценовой сегмент — если есть в фактах).
- Не выдумывай цены, даты, цифры и сравнения, которых нет в блоке бренда.
- Заключение: абзац-итог с практическим советом, как выбрать.
- Живой человеческий язык. ЗАПРЕЩЕНЫ слова с корнями: уникальн- (уникальный/уникальная/уникальные), инноваци-, передов-, лидир-, новатор-, беспрецедент-, несравн-. Подбирай обычные синонимы.
- НЕ используй жирный шрифт (**...**) в тексте — пиши обычными словами.
- НЕ добавляй H1 (# …) — начни сразу с блока «## Коротко».{$kwRule}

Формат: только markdown-тело статьи.
EOT;

        $prompt = $this->withFixHint($prompt, $fixHint);

        return trim($this->generate($prompt, $systemPrompt, local: true, think: false, timeout: 600, temperature: $temperature));
    }

    /**
     * Серия «Бренд X ушёл — чем заменить в России» (docs/foreign_brands_policy.md,
     * раздел «Фрейминг»): статья о российских брендах ниши ушедшего иностранного
     * бренда $anchorName. X — только нейтральная точка отсчёта (nominative use),
     * НЕ участник рейтинга: без оценок «лучше/хуже X», без аффилированности, без
     * «подделка/копия/реплика». Все $brands — равноправные российские замены,
     * описываются СТРОГО из своих фактов (grounded), как в generateListicle.
     *
     * @param array<int,array{name:string,city:?string,facts:string}> $brands российские замены
     * @return string markdown-тело статьи; H1 и JSON-LD строит вызывающий
     */
    public function generateReplacementListicle(
        string $anchorName,
        string $nicheTitle,
        array $brands,
        string $persona,
        string $platformTone,
        ?string $keywords = null,
        ?string $fixHint = null,
        ?float $temperature = null,
        bool $noTables = false, // Дзен не отображает таблицы — сравнение списком
    ): string {
        $n = count($brands);

        $blocks = [];
        foreach ($brands as $i => $b) {
            $num   = $i + 1;
            $city  = ($b['city'] ?? null) ? " (город: {$b['city']})" : '';
            $facts = trim($b['facts']) !== ''
                ? trim($b['facts'])
                : '(фактов мало — опиши коротко и нейтрально, НИЧЕГО не выдумывая)';
            $blocks[] = "БРЕНД {$num} — {$b['name']}{$city}:\nФАКТЫ:\n{$facts}";
        }
        $brandsBlock = implode("\n\n", $blocks);

        $kwRule = ($keywords !== null && trim($keywords) !== '')
            ? "\n- Темы реального спроса (раскрой их естественным текстом по смыслу, но НЕ вставляй эти фразы дословно и НЕ выделяй жирным): {$keywords}"
            : '';

        $comparisonRule = $this->comparisonBlockRule($noTables);

        $systemPrompt = "Ты — {$persona}. Пишешь обзорную статью на русском языке. "
            . "Тон и формат: {$platformTone}. "
            . "{$anchorName} — иностранный бренд, ограничивший работу в России; упоминай его нейтрально, "
            . 'как точку отсчёта (nominative use). ЗАПРЕЩЕНО: оценки ' . $anchorName . ' (лучше/хуже), '
            . "утверждения об аффилированности с {$anchorName}, слова «подделка», «копия», «реплика», "
            . "«официальный представитель», подача {$anchorName} как места покупки. "
            . 'Статья — о российских брендах той же ниши. '
            . 'Используй ИСКЛЮЧИТЕЛЬНО факты из блока каждого бренда — не добавляй данные, которых там нет. '
            . 'Отвечаешь только markdown-текстом статьи, без обёртки ```.';

        $prompt = <<<EOT
Напиши статью «Чем заменить {$anchorName} в России: {$n} российских брендов».

Российские бренды-замены с фактами:

{$brandsBlock}

Требования:
- ОБЪЁМ: 1300–2000 слов (это важно для SEO). Раскрывай каждый бренд подробно.
- НАЧНИ с блока «## Коротко»: 40–60 слов, прямой самодостаточный ответ на запрос «чем заменить {$anchorName} и как выбрать», понятный БЕЗ чтения остального — для AI Overview/сниппета. Упомяни {$anchorName} по имени.
{$comparisonRule}
- Вступление: 2–3 абзаца — {$anchorName} ограничил работу в России (нейтрально, без оценок), поэтому обзор российских брендов той же ниши; как выбирали.
- Для каждого бренда секция: заголовок «## {номер}. {название}», затем 3–4 абзаца СТРОГО по его фактам (ассортимент, особенности, материалы, для кого, ценовой сегмент — если есть в фактах).
- Не выдумывай цены, даты, цифры и сравнения, которых нет в блоке бренда. НЕ сравнивай бренды с {$anchorName} оценочно.
- Заключение: абзац-итог с практическим советом, как выбрать замену.
- Живой человеческий язык. ЗАПРЕЩЕНЫ слова с корнями: уникальн- (уникальный/уникальная/уникальные), инноваци-, передов-, лидир-, новатор-, беспрецедент-, несравн-. Подбирай обычные синонимы.
- НЕ используй жирный шрифт (**...**) в тексте — пиши обычными словами.
- НЕ добавляй H1 (# …) — начни сразу с блока «## Коротко».{$kwRule}

Формат: только markdown-тело статьи.
EOT;

        $prompt = $this->withFixHint($prompt, $fixHint);

        return trim($this->generate($prompt, $systemPrompt, local: true, think: false, timeout: 600, temperature: $temperature));
    }

    /**
     * Серия «уход с маркетплейсов / прямые продажи»: редакционная статья по углу.
     * В отличие от листиклов — БЕЗ бренд-блоков и рейтинга: аналитика по curated-фактам
     * (все цифры — только из $factBlock, MarketplaceExitContext), с мягким позиционированием
     * прямых продаж бренд→покупатель без комиссии с продаж.
     *
     * Wildberries/Ozon/Яндекс.Маркет — действующие компании: строго нейтрально, только фактами.
     * $noTables — для Дзена (не отображает markdown-таблицы).
     */
    public function generateMarketplaceArticle(
        string $angleTitle,
        string $angleBrief,
        string $factBlock,
        string $persona,
        string $platformTone,
        ?string $fixHint = null,
        ?float $temperature = null,
        bool $noTables = false,
    ): string {
        $facts = trim($factBlock) !== ''
            ? trim($factBlock)
            : '(проверенных цифр по этому углу нет — не приводи НИКАКИХ цифр, процентов и сумм)';

        $tableRule = $noTables
            ? "\n- НЕ используй markdown-таблицы (строки с символом |) — площадка их не отображает; любое сравнение давай маркированным списком."
            : '';

        $systemPrompt = "Ты — {$persona}. Пишешь редакционную статью для каталога WEARBASE. "
            . "Тон: {$platformTone}. "
            . 'Wildberries, Ozon, Яндекс.Маркет — ДЕЙСТВУЮЩИЕ компании; упоминай их строго нейтрально, '
            . 'только фактами. ЗАПРЕЩЕНО: оценочные обвинения (кидают/обманывают/мошенники/грабят/ворует/'
            . 'наживается), домыслы о мотивах, любые цифры/проценты/суммы, которых НЕТ в блоке ФАКТОВ. '
            . 'Каждая цифра — только из блока ФАКТОВ. WEARBASE позиционируй мягко, без агрессивной рекламы. '
            . 'Только markdown, без обёртки ```.';

        $prompt = <<<EOT
Напиши редакционную статью «{$angleTitle}».

О чём статья (бриф): {$angleBrief}

ПРОВЕРЕННЫЕ ФАКТЫ (используй только их для любых цифр):
{$facts}

Структура (markdown, начни сразу с «## Коротко», БЕЗ H1):
- «## Коротко» — 40–60 слов, самодостаточный ответ по теме, понятный без чтения остального (для AI Overview/сниппета).
- «## Что происходит» — суть с фактами и цифрами СТРОГО из блока ФАКТОВ; каждая цифра — только оттуда.
- «## Что это значит для бренда» — практические следствия для юнит-экономики и планирования продавца.
- «## Как работают прямые продажи» — модель бренд→покупатель без комиссии с продаж: деньги за заказ идут напрямую бренду, платформа — витрина. Мягко, без рекламного давления.

Требования:
- ОБЪЁМ: 900–1500 слов.
- Живой человеческий язык. ЗАПРЕЩЕНЫ слова с корнями: уникальн-, инноваци-, передов-, лидир-, новатор-, беспрецедент-, несравн-. Подбирай обычные синонимы.
- НЕ используй жирный шрифт (**...**).
- НЕ добавляй H1 (# …).
- Не выдумывай цифр, процентов, сумм и дат, которых нет в блоке ФАКТОВ.{$tableRule}

Формат: только markdown-тело статьи.
EOT;

        $prompt = $this->withFixHint($prompt, $fixHint);

        return trim($this->generate($prompt, $systemPrompt, local: true, think: false, timeout: 600, temperature: $temperature));
    }

    /**
     * Требование к блоку «## Сравнение брендов»: таблицей — для обычных площадок,
     * списком — для Дзена (не поддерживает markdown-таблицы).
     */
    private function comparisonBlockRule(bool $noTables): string
    {
        if ($noTables) {
            return '- Затем блок «## Сравнение брендов» — маркированный список, по пункту на бренд: «{Бренд} — ассортимент: …; размеры: …; цены: …; город: …», ТОЛЬКО факты; где данных нет — пропусти позицию. НЕ используй markdown-таблицы (строки с |) нигде в статье — площадка их не отображает.';
        }

        return '- Затем блок «## Сравнение брендов» — markdown-таблица, столбцы «Бренд | Ассортимент | Размеры | Цены | Город», по строке на бренд, ТОЛЬКО факты; где данных нет — «—». Ничего не выдумывай.';
    }

    /** Self-heal: дописывает в промпт точечную правку по findings гейта (если есть). */
    private function withFixHint(string $prompt, ?string $fixHint): string
    {
        if ($fixHint === null || trim($fixHint) === '') {
            return $prompt;
        }

        return $prompt . "\n\nВАЖНО: предыдущая версия НЕ прошла проверку. Исправь ИМЕННО это и не повторяй (остальное сохрани): {$fixHint}";
    }

    /**
     * Корректорский проход: исправляет орфографию/грамматику/согласование, НЕ меняя
     * смысл, факты, цифры, имена и структуру. Запускать на «голом» тексте ДО линковки
     * (чтобы не сломать markdown-ссылки). Вызывающий обязан сделать гард по длине
     * (модель может «съесть» текст) и откатиться к оригиналу при подозрении.
     */
    public function proofread(string $text): string
    {
        $systemPrompt = 'Ты — корректор русского текста. Исправляешь ТОЛЬКО орфографию, опечатки, '
            . 'пунктуацию и грамматическое согласование. СТРОГО ЗАПРЕЩЕНО: менять смысл, факты, цифры, '
            . 'цены, названия брендов и продуктов, структуру, заголовки (строки с ##), списки; добавлять '
            . 'или удалять предложения; писать любые свои комментарии. Возвращаешь текст один-в-один по '
            . 'структуре, только с исправленными ошибками.';

        $prompt = <<<EOT
Исправь орфографические, пунктуационные и грамматические ошибки в тексте ниже.
Сохрани ВСЁ остальное дословно: структуру, заголовки «##», абзацы, списки, названия
брендов и продуктов (латиницей и кириллицей), цифры и цены. Не добавляй вступлений,
пояснений или выводов — верни ТОЛЬКО исправленный текст.

ТЕКСТ:
{$text}
EOT;

        return trim($this->generate($prompt, $systemPrompt, local: true, think: false, timeout: 600));
    }

    /**
     * SEO/GEO: информационный гид-обзор по нише (опц. городу). В отличие от
     * листикла-рейтинга — без «ТОП-N» и «лучшего выбора»: нейтральный обзор для
     * длинного хвоста, где брендов мало для рейтинга. Бренды описываются СТРОГО
     * из фактов (grounded). Голос/формат задают $persona и $tone.
     *
     * @param array<int,array{name:string,city:?string,facts:string}> $brands
     * @return string markdown-тело (intro → критерии → бренды → как купить → вывод); H1/JSON-LD строит вызывающий
     */
    public function generateGuide(
        string $nicheTitle,
        ?string $city,
        array $brands,
        string $persona,
        string $tone,
        ?string $keywords = null,
        ?string $fixHint = null,
        ?float $temperature = null,
        bool $noTables = false, // Дзен не отображает таблицы — сравнение списком
    ): string {
        $blocks = [];
        foreach ($brands as $b) {
            $bcity = ($b['city'] ?? null) ? " (город: {$b['city']})" : '';
            $facts = trim($b['facts']) !== '' ? trim($b['facts']) : '(фактов мало — упомяни кратко, не выдумывай)';
            $blocks[] = "БРЕНД: {$b['name']}{$bcity}\nФАКТЫ:\n{$facts}";
        }
        $brandsBlock = implode("\n\n", $blocks);
        $geo = $city ? " Все бренды — из города {$city}; подчёркивай гео-контекст." : '';

        $kwRule = ($keywords !== null && trim($keywords) !== '')
            ? "\n- Темы реального спроса (раскрой по смыслу естественным текстом, но НЕ вставляй фразы дословно и НЕ выделяй жирным): {$keywords}"
            : '';

        $comparisonRule = $this->comparisonBlockRule($noTables);

        $systemPrompt = "Ты — {$persona}. Пишешь информационный гид-обзор на русском языке. "
            . "Тон и формат: {$tone}. "
            . 'Используй ИСКЛЮЧИТЕЛЬНО факты из блока каждого бренда — не добавляй данных, которых там нет. '
            . 'Отвечаешь только markdown-текстом, без обёртки ```.';

        $prompt = <<<EOT
Напиши подробный информационный гид «{$nicheTitle}».{$geo}

Бренды с фактами:

{$brandsBlock}

Требования:
- ОБЪЁМ: 1300–2000 слов.
- НАЧНИ с блока «## Коротко»: 40–60 слов, прямой самодостаточный ответ (какие бренды и как выбрать), понятный БЕЗ чтения остального — для AI Overview/сниппета.
{$comparisonRule}
- Вступление: 2–3 абзаца — про нишу{$geo}, для кого этот гид и чем полезен.
- Раздел «## На что обращать внимание при выборе» — 3–5 критериев, обобщая факты брендов.
- Затем по каждому бренду: подзаголовок «## {название}» и 2–3 абзаца СТРОГО по его фактам (ассортимент, материалы, для кого, цены/гео — если есть).
- Раздел «## Где и как купить» — практика (города, магазины, доставка — только если есть в фактах).
- Заключение: абзац-итог с практическим советом.
- Не выдумывай цены, даты, цифры. ЗАПРЕЩЕНЫ слова с корнями: уникальн-, инноваци-, передов-, лидир-, новатор-, беспрецедент-, несравн-.
- НЕ используй жирный шрифт (**...**) в тексте — пиши обычными словами.
- НЕ добавляй H1 (# …) — начни сразу с блока «## Коротко».{$kwRule}

Формат: только markdown-тело.
EOT;

        $prompt = $this->withFixHint($prompt, $fixHint);

        return trim($this->generate($prompt, $systemPrompt, local: true, think: false, timeout: 600, temperature: $temperature));
    }

    /**
     * Извлечение структурированных атрибутов бренда из накопленного текста краула
     * (стадия extract). Grounded: ТОЛЬКО из переданных фактов, не выдумывать.
     * Размерные сетки приходят markdown-таблицами (table-preserving fetch).
     *
     * @return array{styles:string[],categories:string[],gender:?string,sizes:string[],materials:string[],price_segment:?string,geo:?string}
     */
    public function extractBrandAttributes(string $brandName, string $facts): array
    {
        $prompt = <<<EOT
Бренд одежды: {$brandName}

ТЕКСТ С САЙТА БРЕНДА (единственный источник — НЕ выдумывай, чего тут нет):
{$facts}

Извлеки структурированные атрибуты бренда. Отвечай ТОЛЬКО валидным JSON:
{
  "styles": ["casual","streetwear"],
  "categories": ["платья","худи","аксессуары"],
  "gender": "женский|мужской|унисекс|детский|null",
  "sizes": ["XS","S","M","L","XL"] или ["42","44","46"] или ["one size"],
  "materials": ["хлопок","вискоза"],
  "price_segment": "масс-маркет|средний|премиум|люкс|null",
  "geo": "страна/регион производства или null",
  "country": "страна происхождения/родина бренда одним словом (Россия, Италия, Китай, США...) или null",
  "city": "город, где базируется бренд (именно ГОРОД, не страна), или null",
  "founding_year": "год основания бренда, 4 цифры (напр. 2015), или null"
}

Правила:
- Поля, которых НЕТ в тексте → пустой массив [] или null. Не придумывай.
- categories — типы товаров, которые бренд реально продаёт (из каталога/ассортимента).
- sizes — размерный ряд из размерной сетки/карточек (буквенный ИЛИ числовой, как на сайте).
- city — именно город базирования бренда (Москва, Киев, Милан…), НЕ страна и НЕ регион.
- country — страна происхождения бренда (Россия/Италия/…); если бренд явно российский/локальный — «Россия». Не угадывай, если неясно — null.
- founding_year — только если год явно указан в тексте; 4 цифры, иначе null. Не угадывай.
- Значения короткие, в нижнем регистре (кроме размеров и года).
EOT;

        $response = $this->generate($prompt, local: true, think: false, timeout: 180);
        $d = $this->extractJson($response) ?? [];

        $arr = static fn($v): array => is_array($v)
            ? array_values(array_filter(array_map(static fn($x) => trim((string) $x), $v), static fn($x) => $x !== '' && mb_strlen($x) <= 100))
            : [];
        $str = static function ($v): ?string {
            $v = is_string($v) ? trim($v) : '';
            return ($v === '' || strtolower($v) === 'null') ? null : mb_substr($v, 0, 100);
        };
        // Год основания — только правдоподобный 4-значный год (1800–2029), иначе null (анти-галлюцинация).
        $year = static function ($v): ?string {
            $v = (is_string($v) || is_int($v)) ? trim((string) $v) : '';
            return preg_match('/^(1[89]\d{2}|20[0-2]\d)$/', $v) ? $v : null;
        };

        return [
            'styles'        => array_slice($arr($d['styles'] ?? null), 0, 10),
            'categories'    => array_slice($arr($d['categories'] ?? null), 0, 15),
            'gender'        => $str($d['gender'] ?? null),
            'sizes'         => array_slice($arr($d['sizes'] ?? null), 0, 20),
            'materials'     => array_slice($arr($d['materials'] ?? null), 0, 15),
            'price_segment' => $str($d['price_segment'] ?? null),
            'geo'           => $str($d['geo'] ?? null),
            'country'       => $str($d['country'] ?? null),
            'city'          => $str($d['city'] ?? null),
            'founding_year' => $year($d['founding_year'] ?? null),
        ];
    }

    /**
     * Адаптивный промпт для генератора изображений из текста поста (caption).
     * Локальная модель (.119) переводит смысл поста в англоязычный визуальный промпт.
     * null при пустом тексте/ошибке/мусоре → вызывающий падает на статический промпт.
     */
    public function imagePromptFromCaption(string $caption, string $rubric = ''): ?string
    {
        $caption = mb_substr(trim($caption), 0, 1200);
        if ($caption === '') {
            return null;
        }

        $prompt = <<<EOT
По тексту поста соцсети российского бренда одежды придумай ОДИН промпт для генератора изображений (Flux).
Требования: на АНГЛИЙСКОМ, одной строкой, 15–30 слов; эстетичная фотореалистичная редакторская сцена,
отражающая смысл поста; muted tones, natural light; БЕЗ текста, букв, логотипов, водяных знаков, без лиц крупным планом.
Выведи ТОЛЬКО промпт, без кавычек и пояснений.

Текст поста:
{$caption}
EOT;

        try {
            $resp = $this->generate($prompt, local: true, think: false, timeout: 60);
        } catch (\Throwable) {
            return null;
        }

        // Одна строка, без markdown/кавычек.
        $line = trim((string) preg_replace('/\s+/', ' ', strip_tags($resp)));
        $line = trim($line, "\"'`* ");

        return ($line !== '' && mb_strlen($line) >= 10) ? mb_substr($line, 0, 500) : null;
    }

    /**
     * LLM-судья перед авто-публикацией (по мотивам seoloop assess_autopublish):
     * придирчивый редактор оценивает честность и пригодность. Fail-safe: при любой
     * ошибке/непарсе → fabrication=true/publishable=false (статья уходит в черновик).
     *
     * @return array{fabrication:bool,publishable:bool,score:int,issues:string[]}
     */
    public function judgeArticle(string $title, string $content): array
    {
        $systemPrompt = 'Ты — придирчивый главный редактор каталога одежды. Статья рассматривается для '
            . 'АВТОМАТИЧЕСКОЙ публикации (без правки человеком). Оцени честно и строго. Отвечаешь только JSON.';

        $body = mb_substr($content, 0, 9000);
        $prompt = <<<EOT
Заголовок: {$title}

Статья:
{$body}

Оцени:
- "fabrication": true, если есть выдуманные факты/цифры/цитаты или выдуманный «личный опыт» (которых в обзоре каталога быть не должно).
- "publishable": true, если статья реально по теме, полезна, не вода и читается как редакторский обзор.
При сомнении ставь fabrication=true и publishable=false.

Верни ТОЛЬКО валидный JSON без markdown:
{"fabrication": true|false, "publishable": true|false, "score": 0-100, "issues": ["кратко"]}
EOT;

        try {
            $resp = $this->generate($prompt, $systemPrompt, local: true, think: false, timeout: 180);
        } catch (\Throwable) {
            return ['fabrication' => true, 'publishable' => false, 'score' => 0, 'issues' => ['судья недоступен']];
        }
        $d = $this->extractJson($resp);
        if ($d === null) {
            return ['fabrication' => true, 'publishable' => false, 'score' => 0, 'issues' => ['судья: не распарсил JSON']];
        }

        return [
            'fabrication' => (bool) ($d['fabrication'] ?? true),
            'publishable' => (bool) ($d['publishable'] ?? false),
            'score'       => (int) ($d['score'] ?? 0),
            'issues'      => is_array($d['issues'] ?? null)
                ? array_slice(array_map(static fn($x) => (string) $x, $d['issues']), 0, 5)
                : [],
        ];
    }

    /**
     * «Мозг» советника (docs/advisor.md §Мозг, уровень 2): grounded-генерация идей развития
     * на gemma. Модель мэппит бизнес-принципы каналов ($ragContext) на сигналы проекта — это
     * ей по силам, в отличие от глубокой стратегии. Никаких выдуманных цифр: состояние и
     * сигналы даны как факты, принципы — как опора. Confidence выше у идей с опорой на принцип.
     *
     * @param array<string,int|float> $state ключевые метрики снимка
     * @param list<array{message:string,severity:string}> $signals аномалии-сигналы
     * @param string $ragContext нумерованные принципы «#N [Канал · роль]: …»
     * @return list<array{title:string,hypothesis:string,source_signal:?string,rag_citations:array,impact:int,confidence:int,ease:int}>
     */
    public function generateAdvisorIdeas(array $state, array $signals, string $ragContext): array
    {
        $systemPrompt = 'Ты — операционный директор каталога российских брендов одежды wearbase.ru. '
            . 'По состоянию проекта, сигналам и бизнес-принципам из базы знаний предложи КОНКРЕТНЫЕ '
            . 'идеи развития. Опирайся на принципы (в них — реальные механики из бизнес-разборов). '
            . 'Каждая идея практична и проверяема. Отвечай строго JSON.';

        $stateJson    = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
        $signalsBlock = $signals === []
            ? '(явных аномалий нет — предложи идеи роста от текущего состояния)'
            : implode("\n", array_map(
                static fn(array $s) => sprintf('- [%s] %s', $s['severity'] ?? '', $s['message'] ?? ''),
                $signals,
            ));
        $ragBlock = trim($ragContext) !== '' ? $ragContext : '(принципов не нашлось — предлагай без опоры, ставь низкий confidence)';

        $prompt = <<<EOT
СОСТОЯНИЕ ПРОЕКТА (метрики, факты — НЕ выдумывай других цифр):
{$stateJson}

СИГНАЛЫ (детерминированные аномалии):
{$signalsBlock}

БИЗНЕС-ПРИНЦИПЫ ИЗ БАЗЫ ЗНАНИЙ (опора для идей; #N — метка для цитирования):
{$ragBlock}

Задача: предложи 3–6 конкретных, проверяемых идей развития проекта. Каждая идея должна
отвечать на сигнал или метрику и по возможности опираться на принцип выше.

Верни ТОЛЬКО валидный JSON-массив без markdown:
[
  {
    "title": "краткая формулировка идеи (до 100 символов)",
    "hypothesis": "что делаем и почему это сработает (2–4 предложения)",
    "source_signal": "на какой сигнал/метрику отвечает или null",
    "rag_citations": ["#1", "#3"],
    "impact": 1-10,
    "confidence": 1-10,
    "ease": 1-10
  }
]

Правила:
- impact — потенциальный эффект; ease — простота реализации; confidence — уверенность.
- confidence ВЫШЕ, если идея опирается на принцип из базы (укажи его метку в rag_citations);
  без опоры — rag_citations: [] и НИЗКИЙ confidence.
- Никаких выдуманных цифр, дат, обещаний. Идея должна быть проверяема (есть как измерить эффект).
EOT;

        $response = $this->generate($prompt, $systemPrompt, local: true, think: false, timeout: 600);

        $ideas = [];
        foreach ($this->extractJsonArray($response) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            $hypo  = trim((string) ($item['hypothesis'] ?? ''));
            if ($title === '' || $hypo === '') {
                continue;
            }
            $clamp = static fn($v): int => max(1, min(10, (int) $v));
            $cites = is_array($item['rag_citations'] ?? null)
                ? array_values(array_filter(array_map(static fn($x) => trim((string) $x), $item['rag_citations']), static fn($x) => $x !== ''))
                : [];
            $ideas[] = [
                'title'         => mb_substr($title, 0, 255),
                'hypothesis'    => $hypo,
                'source_signal' => $this->nullableString($item['source_signal'] ?? null),
                'rag_citations' => $cites,
                'impact'        => $clamp($item['impact'] ?? 1),
                'confidence'    => $clamp($item['confidence'] ?? 1),
                'ease'          => $clamp($item['ease'] ?? 1),
            ];
        }

        return array_slice($ideas, 0, 6);
    }

    /**
     * On-demand режим советника (docs/advisor.md §Мозг): свободный ответ на вопрос владельца,
     * граундится на текущем состоянии проекта ($state — метрики последнего снимка) и
     * бизнес-принципах базы знаний каналов ($ragContext — нумерованные «#N [Канал · роль]: …»).
     * В отличие от generateAdvisorIdeas — возвращает СВОБОДНЫЙ ТЕКСТ, не JSON. gemma, think=false.
     *
     * @param array<string,int|float> $state ключевые метрики снимка (может быть пуст)
     */
    public function generateAdvisorAnswer(string $question, array $state, string $ragContext): string
    {
        $systemPrompt = 'Ты — операционный директор каталога российских брендов одежды wearbase.ru. '
            . 'Отвечай на вопрос владельца КОНКРЕТНО и по делу, опираясь на текущее состояние проекта '
            . '(метрики) и бизнес-принципы из базы знаний (в них — реальные механики из разборов '
            . 'Гребенюка/Долгова/Токовинина/Соколовского). Не выдумывай цифр, которых нет в состоянии. '
            . 'Если принцип из базы релевантен — сошлись на него. Кратко, без воды.';

        $stateJson = $state === []
            ? '(снимок состояния пуст — метрик нет)'
            : (json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}');
        $ragBlock  = trim($ragContext) !== ''
            ? $ragContext
            : '(релевантных принципов в базе не нашлось — отвечай от состояния и здравого смысла)';

        $prompt = <<<EOT
ВОПРОС ВЛАДЕЛЬЦА:
{$question}

СОСТОЯНИЕ ПРОЕКТА (метрики, факты — НЕ выдумывай других цифр):
{$stateJson}

БИЗНЕС-ПРИНЦИПЫ ИЗ БАЗЫ ЗНАНИЙ (опора для ответа; #N — метка принципа):
{$ragBlock}

Дай конкретный ответ на вопрос. Опирайся на метрики состояния и релевантные принципы
(ссылайся на них по метке #N и каналу). Без общих слов и воды.
EOT;

        return trim($this->generate($prompt, $systemPrompt, local: true, think: false, timeout: 600));
    }

    /**
     * Устойчивое извлечение JSON-массива из ответа модели (вырезает первый [...] даже с
     * мусором вокруг / markdown-обёрткой). Пусто → [].
     *
     * @return array<int,mixed>
     */
    private function extractJsonArray(string $response): array
    {
        $cleaned = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $response) ?? $response;
        if (preg_match('/\[[\s\S]*\]/', $cleaned, $m)) {
            $decoded = json_decode($m[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
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
            'title'       => "{$brandName} — бренд одежды",
            'description' => $description
                ? mb_substr($description, 0, 155)
                : "Каталог одежды бренда {$brandName} в WEARBASE",
            'keywords'    => "{$brandName}, бренд одежды, российский бренд",
        ];
    }
}
