<?php

declare(strict_types=1);

namespace App\Service\Keyword;

/**
 * Единая точка отсева мусорных поисковых фраз — аналог UrlFilter для скрейпа.
 *
 * Зачем: Wordstat матчит запросы по токену имени бренда, и у неоднозначных названий
 * отдаёт порно/пиратский шум («murka onlyfans», «alena bevza порно», «atme слив»).
 * Фильтр релевантности (KeywordService::relevant, GenerateBrandContentCommand::
 * filterRelevantKeywords) такие фразы ПРОПУСКАЕТ — имя бренда в них есть, — после
 * чего они попадают в meta_keywords и в промпт генерации, а модель вплетает их
 * в описание. Поэтому отсев нужен отдельный и fail-closed: сначала блок-лист,
 * потом релевантность.
 *
 * Матчинг по ТОКЕНАМ, а не подстрокам: подстрока даёт ложные срабатывания
 * (unisex→sex, betsy→bet, nudeshop→nude, сливочный→слив, слива→слив).
 *
 * Список правится без деплоя: env KEYWORD_STOPWORDS (через запятую) добавляет
 * токены к базовому набору — та же схема, что SCRAPE_EXCLUDED_DOMAINS у UrlFilter.
 * Совпадение возвращается наружу (match()), чтобы в brand_keyword.blocked_reason
 * было видно, ЧТО сработало: ложное минус-слово так отличается от реального мусора.
 */
class KeywordBlocklist
{
    /** @var string[] */
    private array $extraTokens;

    public function __construct(string $extraStopwords = '')
    {
        $this->extraTokens = array_values(array_filter(array_map(
            static fn (string $t) => mb_strtolower(trim($t), 'UTF-8'),
            explode(',', $extraStopwords),
        ), static fn (string $t) => $t !== ''));
    }
    /** Точное совпадение токена. Инфлексии, которые не покрыть стемом, — списком. */
    private const TOKENS = [
        // adult
        'xxx', 'анал', 'анала', 'anal', 'секс', 'sex', 'nudes', 'onlyfans', 'fansly',
        'mfc', 'dildo', 'дилдо', 'tits', 'сиськи', 'минет', 'трах', 'интим', 'эскорт',
        // утечки приватного контента: 'слива'/'сливочный' — легитимные цвета,
        // поэтому только точные формы, без стема
        'слив', 'сливы', 'слитое', 'слитые', 'sliv', 'leak', 'leaks', 'bunkr',
        'teenmegaworld', 'nsfw',
        // пиратство / варез
        'скачать', 'скачай', 'torrent', 'взлом', 'кряк', 'keygen', 'apk', 'proxy', 'прокси',
        // гемблинг
        'casino', 'ставки', 'bet', '1xbet',
    ];

    /** Токен НАЧИНАЕТСЯ со стема. Только стемы без легитимных fashion-омонимов. */
    private const STEMS = [
        'порн',      // порно, порнуха, порном
        'porn',      // porn, porno, porns, pornhub
        'эротик',    // эротика, эротический
        'анальн',
        'вебкам', 'webcam',
        'торрент',
        'казино',
        'букмекер',
        'скачива',
    ];

    /** Подстрока во всей фразе — для однозначных многословных сочетаний. */
    private const PHRASES = [
        'only fans', 'porn video', 'порно видео', 'секс видео', 'без цензуры',
        'голая грудь', 'голое тело', 'голые фото',
    ];

    public function isBlocked(string $phrase): bool
    {
        return $this->match($phrase) !== null;
    }

    /** Сработавшее минус-слово или null, если фраза чистая. */
    public function match(string $phrase): ?string
    {
        $normalized = mb_strtolower(trim($phrase), 'UTF-8');
        if ($normalized === '') {
            return null;
        }

        foreach (self::PHRASES as $needle) {
            if (str_contains($normalized, $needle)) {
                return $needle;
            }
        }

        foreach (preg_split('/[^\p{L}\p{N}]+/u', $normalized) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            if (in_array($token, self::TOKENS, true) || in_array($token, $this->extraTokens, true)) {
                return $token;
            }
            foreach (self::STEMS as $stem) {
                if (str_starts_with($token, $stem)) {
                    return $stem . '*';
                }
            }
        }

        return null;
    }

    /**
     * @param string[] $phrases
     * @return string[] только чистые, порядок сохранён
     */
    public function filter(array $phrases): array
    {
        return array_values(array_filter($phrases, fn (string $p) => !$this->isBlocked($p)));
    }
}
