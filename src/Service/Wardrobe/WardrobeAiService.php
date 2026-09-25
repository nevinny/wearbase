<?php

namespace App\Service\Wardrobe;

use App\Entity\AiUsageLog;
use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Repository\WardrobeConsentRepository;
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
    public const PHOTO_SCHEMA_VERSION = '2';

    private const CACHE_TTL = 86400;
    private const MAX_SCRAPE_CHARS = 6000;
    private const DAILY_CAP_ERROR = 'Дневной лимит AI-подсказок исчерпан, попробуйте завтра';
    private const CONSENT_ERROR = 'Нет согласия на передачу фото внешнему AI-сервису';
    // Наружу при НЕ-WardrobeAiException (детали — только в логе; URL провайдера никогда не утекает)
    private const GENERIC_ERROR = 'Не удалось обработать запрос, попробуйте позже';

    public function __construct(
        private readonly LlmService $llm,
        private readonly WebScraperService $scraper,
        private readonly WildberriesAdapter $wbAdapter,
        private readonly WardrobeAiMeter $meter,
        private readonly AiUsageTracker $usageTracker,
        private readonly CacheInterface $cache,
        private readonly string $visionModel,
        private readonly bool $visionLocal,
        private readonly string $localModel,
        private readonly LoggerInterface $wardrobeAiLogger,
        private readonly WardrobeConsentRepository $consents,
    ) {
    }

    /**
     * Единственный гейт согласия на фото: фото уходит в модель только отсюда (веб,
     * CLI, фоновый воркер черновиков, telegram-бот), поэтому вопрос «нужно ли
     * отдельное согласие» решается по месту отправки, а не по месту загрузки.
     *
     * visionLocal — обработка на оборудовании Оператора, третьей стороны нет:
     * её покрывает общее согласие при регистрации (фото названы в его тексте).
     * Иначе фото уходит внешнему сервису — нужна отметка субъекта, данная под
     * текстом, который эту передачу описывает.
     */
    public function externalPhotoConsentRequired(?User $subject): bool
    {
        if ($this->visionLocal) {
            return false;
        }

        return $subject === null || !($this->consents->findForSubject($subject)?->coversExternalPhotoTransfer() ?? false);
    }

    /** @return array{ok:bool,fields?:array,confidence?:string,error?:string} */
    public function suggestFromPhoto(string $path, ?User $user = null): array
    {
        if ($this->externalPhotoConsentRequired($user)) {
            $this->logError(AiUsageLog::FEATURE_WARDROBE_PHOTO, $user, self::CONSENT_ERROR);

            return ['ok' => false, 'error' => self::CONSENT_ERROR];
        }

        $hash = @sha1_file($path);
        if ($hash === false) {
            $error = 'Не удалось прочитать фото';
            $this->logError(AiUsageLog::FEATURE_WARDROBE_PHOTO, $user, $error, ['path' => $path]);

            return ['ok' => false, 'error' => $error];
        }

        $model = $this->visionLocal ? $this->localModel : $this->visionModel;
        $provider = $this->visionLocal ? 'local' : 'remote';
        $cacheKey = 'wardrobe_ai_photo_'.self::PHOTO_SCHEMA_VERSION.'_'.sha1($provider.':'.$model).'_'.$hash;

        try {
            return $this->cache->get(
                $cacheKey,
                function (ItemInterface $item) use ($path, $user): array {
                    $item->expiresAfter(self::CACHE_TTL);

                    return $this->analyzePhoto($path, $user);
                },
            );
        } catch (\Throwable $e) {
            $this->logError(AiUsageLog::FEATURE_WARDROBE_PHOTO, $user, $e->getMessage(), ['hash' => $hash]);

            return ['ok' => false, 'error' => $e instanceof WardrobeAiException ? $e->getMessage() : self::GENERIC_ERROR];
        }
    }

    /** @return array<int, array{name:?string,category:?string,color:?string,confidence:string}> */
    public function recognizeOutfitPhoto(string $path, ?User $user = null): array
    {
        if ($this->externalPhotoConsentRequired($user)) {
            $this->logError(AiUsageLog::FEATURE_WARDROBE_PHOTO, $user, self::CONSENT_ERROR, ['flow' => 'outfit']);

            return [];
        }

        $hash = @sha1_file($path);
        if ($hash === false) {
            return [];
        }
        try {
            return $this->cache->get('wardrobe_ai_outfit_'.$hash, function (ItemInterface $item) use ($path, $user): array {
                $item->expiresAfter(self::CACHE_TTL);
                if (!$this->visionLocal && !$this->meter->allowed()) {
                    throw new WardrobeAiException(self::DAILY_CAP_ERROR);
                }
                $prompt = <<<'PROMPT'
Определи отдельные предметы одежды, обуви и аксессуары, которые надеты на человеке.
Верни ТОЛЬКО JSON без markdown: {"garments":[{"name":"короткое название","category":"категория","color":"цвет","confidence":"high|med|low"}]}.
Не описывай тело, возраст, пол, фон и внешность человека. Не выдумывай невидимые вещи. Максимум 12 предметов.
PROMPT;
                if (!$this->visionLocal) {
                    $this->meter->record();
                }
                $model = $this->visionLocal ? $this->localModel : $this->visionModel;
                $data = $this->extractJson($this->llm->generateVision($prompt, [$path], $model, $this->visionLocal));
                $this->usageTracker->record($user, AiUsageLog::FEATURE_WARDROBE_PHOTO);
                $garments = is_array($data['garments'] ?? null) ? array_slice($data['garments'], 0, 12) : [];

                return array_values(array_filter(array_map(function (mixed $garment): ?array {
                    if (!is_array($garment)) {
                        return null;
                    }
                    $name = $this->cleanString($garment['name'] ?? null, 100);
                    $category = $this->cleanString($garment['category'] ?? null, 100);
                    $color = $this->cleanString($garment['color'] ?? null, 100);
                    if ($name === null && $category === null && $color === null) {
                        return null;
                    }
                    return ['name' => $name, 'category' => $category, 'color' => $color, 'confidence' => $this->normalizeConfidence($garment['confidence'] ?? null)];
                }, $garments)));
            });
        } catch (\Throwable $exception) {
            $this->logError(AiUsageLog::FEATURE_WARDROBE_PHOTO, $user, $exception->getMessage(), ['hash' => $hash, 'flow' => 'outfit']);
            return [];
        }
    }

    /**
     * Батч без фото и без ссылки на карточку (или для одного лишь сезона, когда цвет/
     * материал уже известны из WB/vision, а сезон — нет): вход — что уже известно о
     * вещи (имя, категория, materialText — из WB или пусто), выход — colorName/
     * materialText/season по каждому id. Локальная модель, без консент-гейта и без
     * WardrobeAiMeter — как и visionLocal=true, обработка целиком на риге Оператора,
     * фото не участвует (см. докблок externalPhotoConsentRequired()). Батч экономит
     * вызовы модели: 8–12 вещей за один запрос вместо одного вызова на вещь.
     *
     * @param array<int, array{id:int,name:?string,category:?string,materialText:?string}> $rows
     * @return array<int, array{colorName:?string,materialText:?string,season:?string}> по id
     */
    public function suggestAttributesFromNames(array $rows): array
    {
        $rows = array_values(array_filter($rows, static fn (array $r): bool => isset($r['id'])));
        if ($rows === []) {
            return [];
        }

        $catalog = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) ($r['name'] ?? ''),
            'category' => $r['category'] ?? null,
            'materialText' => $r['materialText'] ?? null,
        ], $rows);
        $knownIds = array_column($catalog, 'id');

        $systemPrompt = <<<'TXT'
        Ты извлекаешь атрибуты одежды для личного гардероба из УЖЕ ИЗВЕСТНЫХ данных (название,
        категория, иногда состав) — фото нет. Ложный атрибут хуже пустого: никогда не
        выдумывай то, чего нет в названии или в уже известных данных.

        Верни ТОЛЬКО валидный JSON без markdown, ровно по одному объекту на каждый id из
        списка: {"items":[{"id":число,"colorName":"цвет или null","materialText":"материал
        или null","season":"all|spring|summer|autumn|winter или null"}]}.

        Правила:
        - colorName — свободная строка на русском, как цвет назван в названии/данных
          (например «пыльная роза», «светло-бежевый»), не своди к базовым цветам. Если цвет
          нигде не назван явно — null, не гадай.
        - materialText — только если материал/ткань явно есть в названии или уже известны;
          можно уточнить уже известное значение, не выдумывая новое. Если неизвестно — null.
        - season — у него другое правило, он не может просто пропасть: если по названию и
          категории понятно, что это за вещь (футболка, платье, водолазка, пальто и т.п.),
          выведи сезон из категории и упомянутой ткани (водолазки/шерсть/экокожа/пальто — не
          лето; футболки/лён/шорты — не зима; базовые вещи без сезонной привязки — "all").
          null для season допустим ТОЛЬКО если из названия вообще не понятно, что это за вещь.
        TXT;

        $prompt = 'ВЕЩИ (JSON): '.json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            $response = $this->llm->generate($prompt, $systemPrompt, local: true, think: false);
        } catch (\Throwable $e) {
            $this->logError(AiUsageLog::FEATURE_WARDROBE_ATTRIBUTES, null, $e->getMessage());

            return [];
        }

        $data = $this->extractJson($response);
        $entries = is_array($data['items'] ?? null) ? $data['items'] : [];

        $out = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = filter_var($entry['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || !in_array($id, $knownIds, true)) {
                continue;
            }
            $out[$id] = [
                'colorName' => $this->limitedString($entry['colorName'] ?? null, 100),
                'materialText' => $this->limitedString($entry['materialText'] ?? null, 2000),
                'season' => $this->normalizeSeason($entry['season'] ?? null),
            ];
        }

        $this->usageTracker->recordLocal(null, AiUsageLog::FEATURE_WARDROBE_ATTRIBUTES, $this->localModel);

        return $out;
    }

    private function normalizeSeason(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, ['all', 'spring', 'summer', 'autumn', 'winter'], true) ? $value : null;
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

            return ['ok' => false, 'error' => $e instanceof WardrobeAiException ? $e->getMessage() : self::GENERIC_ERROR];
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
        if (!$this->visionLocal && !$this->meter->allowed()) {
            throw new WardrobeAiException(self::DAILY_CAP_ERROR);
        }

        if (!$this->visionLocal) {
            $this->meter->record();
        }
        $model = $this->visionLocal ? $this->localModel : $this->visionModel;
        $response = $this->llm->generateVision($this->photoPrompt(), [$path], $model, $this->visionLocal);
        $this->usageTracker->record($user, AiUsageLog::FEATURE_WARDROBE_PHOTO);

        return $this->parsePhotoResponse($response, $model);
    }

    /**
     * Тот же анализ фото, что и продовый suggestFromPhoto/analyzePhoto (промпт + парсинг
     * ответа), но с произвольной локальной моделью и БЕЗ согласия/лимитов/кеша/usage-лога —
     * нужен только для app:bench:vision (сравнение моделей на каталожных фото). Прод этим
     * методом не пользуется.
     *
     * @return array{ok:bool,fields?:array,confidence?:string,error?:string}
     */
    public function analyzePhotoWithLocalModel(string $path, string $model): array
    {
        $response = $this->llm->generateVision($this->photoPrompt(), [$path], $model, true);

        return $this->parsePhotoResponse($response, $model);
    }

    private function photoPrompt(): string
    {
        $categories = implode(', ', WardrobeItem::SUGGESTED_CATEGORIES);

        return <<<EOT
Ты определяешь параметры одежды/обуви по фото для личного гардероба. Отвечай ТОЛЬКО
валидным JSON без markdown. Не выдумывай не видимое на фото — такие поля null.

Верни JSON:
{
  "category": "категория (предпочтительно одна из: {$categories}, либо своя короткая на русском) или null",
  "name": "короткое русское название-описание, например «Белая oversize футболка»",
  "color": "цвет или null",
  "material": "состав ТОЛЬКО с читаемой бирки, не угадывай по виду ткани, иначе null",
  "season": "all|spring|summer|autumn|winter или null; если сезон неоднозначен, null",
  "type": "фасон/крой или null",
  "size": "размер ТОЛЬКО если видна читаемая бирка, иначе null",
  "confidence": "high|med|low"
}
EOT;
    }

    /** @return array{ok:bool,fields?:array,model?:string,schemaVersion?:string,confidence?:string} */
    private function parsePhotoResponse(string $response, string $model): array
    {
        $data = $this->extractJson($response);
        if ($data === null) {
            throw new WardrobeAiException('Не удалось распознать фото');
        }

        return [
            'ok'         => true,
            'fields'     => $this->normalizePhotoFields($data),
            'model'      => $model,
            'schemaVersion' => self::PHOTO_SCHEMA_VERSION,
            'confidence' => $this->normalizeConfidence($data['confidence'] ?? null),
        ];
    }

    private function cleanString(mixed $value, int $length): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $length);
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
            throw new WardrobeAiException(self::DAILY_CAP_ERROR);
        }

        $text = $this->scraper->fetchCleanText($url, keepTables: true);
        if ($text === null || trim($text) === '') {
            throw new WardrobeAiException('Не удалось получить содержимое страницы');
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
            throw new WardrobeAiException('Не удалось распознать содержимое страницы');
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

    /** Structured fields use the same names and season values as WardrobeItemFormType. */
    private function normalizePhotoFields(array $d): array
    {
        $season = $this->nullableString($d['season'] ?? null);
        $season = match (mb_strtolower($season ?? '')) {
            'all', 'всесезон' => 'all',
            'spring', 'весна' => 'spring',
            'summer', 'лето' => 'summer',
            'autumn', 'осень' => 'autumn',
            'winter', 'зима' => 'winter',
            default => null,
        };
        $cut = $this->nullableString($d['type'] ?? null);

        return [
            'category' => $this->limitedString($d['category'] ?? null, 100),
            'name' => $this->limitedString($d['name'] ?? null, 255),
            'size' => $this->limitedString($d['size'] ?? null, 50),
            'colorName' => $this->limitedString($d['color'] ?? null, 100),
            'materialText' => $this->limitedString($d['material'] ?? null, 2000),
            'season' => $season,
            'notes' => $cut === null ? null : 'Фасон: '.mb_substr($cut, 0, 500),
        ];
    }

    private function limitedString(mixed $value, int $length): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : mb_substr($value, 0, $length);
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
