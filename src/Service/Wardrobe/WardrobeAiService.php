<?php

namespace App\Service\Wardrobe;

use App\Entity\AiUsageLog;
use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Service\AiUsageTracker;
use App\Service\LlmService;
use App\Service\WardrobeAiMeter;
use App\Service\WebScraperService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * AI-ассист добавления вещи в гардероб:
 * - suggestFromPhoto: vision LLM по фото → категория/название/размер/заметки.
 * - suggestFromUrl: wildberries.ru → WildberriesAdapter (без LLM); иначе
 *   WebScraperService + текстовый LLM-экстракт той же схемы.
 *
 * Кеш (sha1 фото / нормализованный URL) — 24ч, чтобы повторный запрос по тому же
 * фото/ссылке не бил по LLM/бюджету. Только УСПЕШНЫЕ результаты кладутся в кеш —
 * ошибки бросаются исключением из callback и Symfony Cache их не сохраняет.
 * Дневной потолок — WardrobeAiMeter (общий на инсталляцию, api_usage_daily);
 * per-user частота — rate_limiter (wardrobe_ai) в контроллере.
 */
class WardrobeAiService
{
    private const CACHE_TTL = 86400;
    private const MAX_SCRAPE_CHARS = 6000;
    private const DAILY_CAP_ERROR = 'Дневной лимит AI-подсказок исчерпан, попробуйте завтра';

    public function __construct(
        private readonly LlmService $llm,
        private readonly WebScraperService $scraper,
        private readonly WildberriesAdapter $wbAdapter,
        private readonly WardrobeAiMeter $meter,
        private readonly AiUsageTracker $usageTracker,
        private readonly CacheInterface $cache,
        private readonly string $visionModel,
        private readonly LoggerInterface $wardrobeAiLogger,
    ) {
    }

    /** @return array{ok:bool,fields?:array,confidence?:string,error?:string} */
    public function suggestFromPhoto(string $path, ?User $user = null): array
    {
        $hash = @sha1_file($path);
        if ($hash === false) {
            $error = 'Не удалось прочитать фото';
            $this->logError(AiUsageLog::FEATURE_WARDROBE_PHOTO, $user, $error, ['path' => $path]);

            return ['ok' => false, 'error' => $error];
        }

        try {
            return $this->cache->get(
                "wardrobe_ai_photo_{$hash}",
                function (ItemInterface $item) use ($path, $user): array {
                    $item->expiresAfter(self::CACHE_TTL);

                    return $this->analyzePhoto($path, $user);
                },
            );
        } catch (\Throwable $e) {
            $this->logError(AiUsageLog::FEATURE_WARDROBE_PHOTO, $user, $e->getMessage(), ['hash' => $hash]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array{ok:bool,fields?:array,imageUrl?:?string,confidence?:string,error?:string} */
    public function suggestFromUrl(string $url, ?User $user = null): array
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            $error = 'Некорректная ссылка';
            $this->logError(AiUsageLog::FEATURE_WARDROBE_URL, $user, $error, ['url' => $url]);

            return ['ok' => false, 'error' => $error];
        }

        $cacheKey = 'wardrobe_ai_url_' . sha1($this->normalizeUrl($url));

        try {
            return $this->cache->get(
                $cacheKey,
                function (ItemInterface $item) use ($url, $user): array {
                    $item->expiresAfter(self::CACHE_TTL);

                    return $this->analyzeUrl($url, $user);
                },
            );
        } catch (\Throwable $e) {
            $this->logError(AiUsageLog::FEATURE_WARDROBE_URL, $user, $e->getMessage(), ['url' => $url]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Общий хвост ошибок: журнал (ai_usage_log, best-effort) + файловый лог (var/log/wardrobe_ai.log). */
    private function logError(string $feature, ?User $user, string $error, array $context = []): void
    {
        $this->usageTracker->recordError($user, $feature, $error);
        $this->wardrobeAiLogger->error($error, $context + ['feature' => $feature, 'user_id' => $user?->getId()]);
    }

    private function analyzePhoto(string $path, ?User $user): array
    {
        if (!$this->meter->allowed()) {
            throw new \RuntimeException(self::DAILY_CAP_ERROR);
        }

        $categories = implode(', ', WardrobeItem::SUGGESTED_CATEGORIES);
        $prompt = <<<EOT
Ты определяешь параметры одежды/обуви по фото для личного гардероба. Отвечай ТОЛЬКО
валидным JSON без markdown. Не выдумывай не видимое на фото — такие поля null.

Верни JSON:
{
  "category": "категория (предпочтительно одна из: {$categories}, либо своя короткая на русском) или null",
  "name": "короткое русское название-описание, например «Белая oversize футболка»",
  "color": "цвет или null",
  "material": "материал, если явно видно/указано на бирке, иначе null",
  "season": "лето|демисезон|зима|всесезон или null",
  "type": "фасон/крой или null",
  "size": "размер ТОЛЬКО если видна читаемая бирка, иначе null",
  "confidence": "high|med|low"
}
EOT;

        $this->meter->record();
        $response = $this->llm->generateVision($prompt, [$path], $this->visionModel);
        $this->usageTracker->record($user, AiUsageLog::FEATURE_WARDROBE_PHOTO);
        $data = $this->extractJson($response);
        if ($data === null) {
            throw new \RuntimeException('Не удалось распознать фото');
        }

        return [
            'ok'         => true,
            'fields'     => $this->normalizePhotoFields($data),
            'confidence' => $this->normalizeConfidence($data['confidence'] ?? null),
        ];
    }

    private function analyzeUrl(string $url, ?User $user): array
    {
        if (str_contains(strtolower($url), 'wildberries.ru')) {
            $wb = $this->wbAdapter->fetch($url);
            if ($wb !== null) {
                return [
                    'ok' => true,
                    'fields' => [
                        'category'   => null,
                        'name'       => $wb['name'],
                        'size'       => $wb['sizes'],
                        'price'      => $wb['price'],
                        'productUrl' => $url,
                        'notes'      => null,
                    ],
                    'imageUrl'   => $wb['imageUrl'],
                    'confidence' => 'high',
                ];
            }
            // fail-soft: WB недоступен/формат сломался — падаем в scraper+LLM ниже
        }

        if (!$this->meter->allowed()) {
            throw new \RuntimeException(self::DAILY_CAP_ERROR);
        }

        $text = $this->scraper->fetchCleanText($url, keepTables: true);
        if ($text === null || trim($text) === '') {
            throw new \RuntimeException('Не удалось получить содержимое страницы');
        }
        $text = mb_substr($text, 0, self::MAX_SCRAPE_CHARS);

        $categories = implode(', ', WardrobeItem::SUGGESTED_CATEGORIES);
        $prompt = <<<EOT
Извлеки параметры товара со страницы карточки товара. Не выдумывай данные, которых
нет на странице — такие поля null.

ТЕКСТ СТРАНИЦЫ:
{$text}

Верни ТОЛЬКО валидный JSON без markdown:
{
  "category": "категория (предпочтительно одна из: {$categories}, либо своя короткая на русском) или null",
  "name": "короткое русское название товара",
  "size": "размер(ы) как на странице (строка) или null",
  "price": число в рублях (целое) или null,
  "notes": "краткое описание (материал/цвет/бренд), если есть, иначе null",
  "confidence": "high|med|low"
}
EOT;

        $this->meter->record();
        // Remote (OpenRouter, та же дешёвая модель, что и vision): локальный ollama с прода недоступен
        $response = $this->llm->generate($prompt, model: $this->visionModel, timeout: 30);
        $this->usageTracker->record($user, AiUsageLog::FEATURE_WARDROBE_URL);
        $data = $this->extractJson($response);
        if ($data === null) {
            throw new \RuntimeException('Не удалось распознать содержимое страницы');
        }

        return [
            'ok' => true,
            'fields' => [
                'category'   => $this->nullableString($data['category'] ?? null),
                'name'       => $this->nullableString($data['name'] ?? null),
                'size'       => $this->nullableString($data['size'] ?? null),
                'price'      => is_numeric($data['price'] ?? null) ? (int) $data['price'] : null,
                'productUrl' => $url,
                'notes'      => $this->nullableString($data['notes'] ?? null),
            ],
            'confidence' => $this->normalizeConfidence($data['confidence'] ?? null),
        ];
    }

    /** color/material/season/type → notes; null-поля пропускаются. */
    private function normalizePhotoFields(array $d): array
    {
        $notesParts = [];
        foreach ([
            'Цвет'     => $this->nullableString($d['color'] ?? null),
            'Материал' => $this->nullableString($d['material'] ?? null),
            'Сезон'    => $this->nullableString($d['season'] ?? null),
            'Фасон'    => $this->nullableString($d['type'] ?? null),
        ] as $label => $value) {
            if ($value !== null) {
                $notesParts[] = "{$label}: {$value}";
            }
        }

        return [
            'category' => $this->nullableString($d['category'] ?? null),
            'name'     => $this->nullableString($d['name'] ?? null),
            'size'     => $this->nullableString($d['size'] ?? null),
            'notes'    => $notesParts !== [] ? implode('; ', $notesParts) : null,
        ];
    }

    private function normalizeConfidence(mixed $value): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, ['high', 'med', 'low'], true) ? $value : 'low';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return ($value === '' || strtolower($value) === 'null') ? null : $value;
    }

    /** Нормализация URL для ключа кеша: без query/fragment (WB size/спп-параметры не влияют на карточку). */
    private function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);

        return sprintf(
            '%s://%s%s',
            $parts['scheme'] ?? 'https',
            strtolower((string) ($parts['host'] ?? '')),
            rtrim((string) ($parts['path'] ?? ''), '/'),
        );
    }

    /** Устойчивое извлечение JSON-объекта из ответа модели (терпимо к markdown-обёртке). */
    private function extractJson(string $response): ?array
    {
        $cleaned = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $response);
        if (preg_match('/\{[\s\S]*\}/', $cleaned ?? $response, $m)) {
            $decoded = json_decode($m[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
