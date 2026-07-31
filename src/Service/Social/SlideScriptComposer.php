<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Brand;
use App\Entity\BrandAttribute;
use App\Repository\BrandAttributeRepository;
use App\Service\BrandRagService;
use App\Service\ContentValidator;
use App\Service\LlmService;
use Symfony\Component\Yaml\Yaml;

/**
 * Сценарий надписей поста-галереи v3 — лестница внимания на честных фактах бренда, не
 * comment-bait («пиши цифру» — Meta демоутит голосовалки, и это ничего не доказывает про
 * бренд). Собирает: hookA/hookB (кадры 1–2), 0..3 бита-факта (кадры 4,6,8…) и развязку
 * (последний кадр: имя, «город · категории», просьба сохранить).
 *
 * ЛЕСТНИЦА ХУКОВ (верхняя доступная ступень выигрывает; данные бренда решают, а не рандом —
 * повторный рендер того же бренда обязан дать тот же хук, поэтому compose() без seed):
 *  H1 «ушедший бренд» — slug встречается в alternatives departed_brands.yaml;
 *  H2 «угадай город»  — city задан и это не Москва/СПб (там угадывать нечего);
 *  H3 «сначала факты» — есть ≥1 валидный бит;
 *  H4 «просто посмотри» — общий фолбэк.
 *
 * БИТЫ. Сначала grounded (LLM на выдержках BrandRagService, тот же гейт качества, что у
 * FounderStoryCaptionSource), затем детерминированный «добор»: год основания → категории →
 * материал → (только парой, только последними) платформенный факт про комиссию маркетплейса.
 * Каждый LLM-кандидат проходит цепочку guard-проверок (длина/пиксели/запрещённые
 * символы/AI-фразы/глитчи/цифры и слова только из выдержек/пустышки) — провал ЛЮБОЙ ступени
 * выбрасывает кандидата насовсем, без ретрая: подгонять текст под правила опаснее, чем
 * потерять один факт из восьми.
 *
 * scriptKey фиксирует РЕАЛИЗОВАННУЮ ступень + источник битов, напр. 'h2.city|b.rag2|c.save' —
 * по нему SocialGenerateCommand решает held (LLM-биты — ручной просмотр первой партии) vs
 * scheduled, и app:social:evaluate группирует closed-loop.
 */
class SlideScriptComposer
{
    private const HOOK_FONT_SIZE = 54;

    private const DEPARTED_HOOK_B = 'Но не копия.';
    private const CITY_HOOK_A = 'Угадай город.';
    private const CITY_HOOK_B = 'Скажу в конце.';
    private const NAME_AT_END_HOOK_A = 'Имя — в конце.';
    private const GENERIC_HOOK_B = 'Просто посмотри.';
    private const FINALE_ASK = 'Сохрани, чтобы не искать.';

    /** Мегаполисы, где «угадай город» не работает — угадывать нечего. */
    private const NO_GUESS_CITIES = ['Москва', 'Санкт-Петербург'];

    /**
     * B4+B5 — платформенный факт (проверяемый, PillarCaptionSource::PILLARS), но только как
     * пара и только последними битами: разрозненно «мы — 0%» без пары про маркетплейс не несёт
     * смысла.
     */
    private const MARKETPLACE_BIT = 'Маркетплейс — 30–67%.';
    private const OURS_BIT = 'Мы — 0%.';

    /**
     * Материалы из brand_attribute (LLM-extract) вперемешку с мусором (polyester/pongee/иностр.
     * транслит) — на слайд идут только узнаваемые русские названия натуральных/базовых тканей.
     *
     * @var list<string>
     */
    private const MATERIAL_WHITELIST = [
        'хлопок', 'лён', 'лен', 'шерсть', 'футер', 'деним', 'кожа', 'натуральная кожа',
        'шёлк', 'шелк', 'кашемир', 'замша', 'натуральная замша', 'трикотаж', 'твид',
        'вельвет', 'бархат', 'атлас', 'лиоцелл', 'модал', 'велюр',
    ];

    /**
     * Слова-корни непроверяемых претензий («шьют вручную», «эксклюзивно», «дешевле») —
     * пропускаются только если тот же корень дословно есть в выдержках бренда.
     */
    private const CLAIM_PATTERN = '/(шьют|шьёт|шили|сшит\w*|ручн\w*|вручную|дешев\w*|дёшев\w*|лимит\w*|эксклюз\w*|лучш\w*|единствен\w*|только\s+у\s+нас)/iu';

    /** Общие слова каталога — строка из них одних не факт, а филлер (см. isFillerLine()). */
    private const FILLER_ROOTS = ['бренд', 'одежд', 'вещ', 'качеств', 'стил', 'мод', 'коллекц', 'магазин', 'покупател', 'клиент'];

    private const BIT_SYSTEM_PROMPT = 'Ты пишешь очень короткие надписи для кадров видео. Только по-русски. '
        . 'Каждая надпись — не длиннее 20 знаков вместе с пробелами. Отвечаешь списком строк, без нумерации и пояснений.';

    /** @var list<array<string,mixed>>|null */
    private ?array $departedRecords = null;

    public function __construct(
        private readonly LlmService $llm,
        private readonly BrandRagService $rag,
        private readonly ContentValidator $validator,
        private readonly BrandAttributeRepository $attributes,
        private readonly SlideTextBudget $budget,
        private readonly string $departedYamlPath,
    ) {
    }

    public function compose(Brand $brand, int $totalSlides): SlideScript
    {
        $budget = SlideScript::maxBits($totalSlides);
        $usedKeys = [];

        $hookA = null;
        $hookB = null;
        $hookKey = null;

        $departed = $this->departedMatch($brand);
        $departedHookA = $departed !== null ? 'Вместо ' . $departed['departed'] . '?' : null;
        if ($departedHookA !== null && $this->fitsLine($departedHookA)) {
            $hookA = $departedHookA;
            $hookB = self::DEPARTED_HOOK_B;
            $hookKey = 'h1.departed';
        } elseif ($this->cityEligibleForGuess($brand)) {
            $hookA = self::CITY_HOOK_A;
            $hookB = self::CITY_HOOK_B;
            $hookKey = 'h2.city';
        }

        if ($hookA !== null && $hookB !== null) {
            $usedKeys[$this->dedupKey($hookA)] = true;
            $usedKeys[$this->dedupKey($hookB)] = true;
        }

        ['bits' => $bits, 'ragCount' => $ragCount, 'detCount' => $detCount] = $this->composeBits($brand, $budget, $usedKeys);

        if ($hookA === null) {
            if ($bits !== []) {
                $hookA = self::NAME_AT_END_HOOK_A;
                $hookB = $this->factsHookB(count($bits));
                $hookKey = 'h3.facts';
            } else {
                $hookA = self::NAME_AT_END_HOOK_A;
                $hookB = self::GENERIC_HOOK_B;
                $hookKey = 'h4.generic';
            }
        }

        $scriptKey = sprintf('%s|b.%s|c.save', $hookKey, $this->bitsSourceKey($ragCount, $detCount, count($bits)));

        return new SlideScript(
            hookA: $hookA,
            hookB: (string) $hookB,
            bits: $bits,
            finaleTitle: trim((string) $brand->getTitle()),
            finaleMeta: $this->finaleMeta($brand),
            finaleAsk: self::FINALE_ASK,
            scriptKey: $scriptKey,
        );
    }

    // --- Хук: лестница ------------------------------------------------------------------

    private function factsHookB(int $count): string
    {
        return match (min(3, max(1, $count))) {
            1 => 'Сначала — один факт.',
            2 => 'Сначала — два факта.',
            default => 'Сначала — три факта.',
        };
    }

    private function cityEligibleForGuess(Brand $brand): bool
    {
        $city = trim((string) $brand->getCity());

        return $city !== '' && !in_array($city, self::NO_GUESS_CITIES, true);
    }

    /** @return array<string,mixed>|null запись departed_brands.yaml, чьи alternatives содержат slug бренда */
    private function departedMatch(Brand $brand): ?array
    {
        $slug = trim((string) $brand->getSlug());
        if ($slug === '') {
            return null;
        }

        foreach ($this->loadDepartedRecords() as $record) {
            $alternatives = array_map('strval', (array) ($record['alternatives'] ?? []));
            if (in_array($slug, $alternatives, true)) {
                return $record;
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    private function loadDepartedRecords(): array
    {
        if ($this->departedRecords === null) {
            $parsed = is_file($this->departedYamlPath) ? Yaml::parseFile($this->departedYamlPath) : [];
            $this->departedRecords = is_array($parsed) ? $parsed : [];
        }

        return $this->departedRecords;
    }

    // --- Развязка -------------------------------------------------------------------------

    /**
     * «{Город} · {до 2 категорий}», ≤30 знаков, жадно по числу категорий. Единственная
     * степень свободы — категории бренда (BrandAttribute::NAME_CATEGORY), выбор которых уже
     * определён заранее (не привязан к битам): развязка отвечает на общий вопрос «что это за
     * бренд», а не пересказывает уже показанные факты.
     */
    private function finaleMeta(Brand $brand): string
    {
        $parts = [];
        $city = trim((string) $brand->getCity());
        if ($city !== '') {
            $parts[] = $city;
        }

        $categories = $this->attributes->findValuesByBrandAndName($brand, BrandAttribute::NAME_CATEGORY);
        $chosenCategories = [];
        foreach ($categories as $category) {
            if (count($chosenCategories) >= 2) {
                break;
            }
            $candidate = [...$chosenCategories, $category];
            $text = $this->joinMeta($city, $candidate);
            if (mb_strlen($text) > SlideScript::FINALE_META_MAX_CHARS) {
                break;
            }
            $chosenCategories = $candidate;
        }

        if ($chosenCategories !== []) {
            return $this->joinMeta($city, $chosenCategories);
        }

        // Ни города, ни категорий (данных о бренде почти нет) — не оставляем строку пустой.
        return $parts === [] ? 'Российский бренд' : implode(' · ', $parts);
    }

    /** @param list<string> $categories */
    private function joinMeta(string $city, array $categories): string
    {
        $tail = implode(', ', $categories);

        return $city !== '' ? $city . ' · ' . $tail : $this->capitalize($tail);
    }

    // --- Биты -------------------------------------------------------------------------------

    /**
     * @param array<string,bool> $usedKeys дедуп-ключи, уже занятые хуком (мутируется по ссылке
     *                                      извне не нужно — копия внутри метода)
     *
     * @return array{bits:list<string>,ragCount:int,detCount:int}
     */
    private function composeBits(Brand $brand, int $budget, array $usedKeys): array
    {
        if ($budget <= 0) {
            return ['bits' => [], 'ragCount' => 0, 'detCount' => 0];
        }

        $bits = [];
        $ragCount = 0;
        foreach ($this->groundedCandidates($brand) as $candidate) {
            if (count($bits) >= $budget) {
                break;
            }
            $key = $this->dedupKey($candidate);
            if (isset($usedKeys[$key])) {
                continue;
            }
            $usedKeys[$key] = true;
            $bits[] = $candidate;
            $ragCount++;
        }

        $detCount = 0;
        if (count($bits) < $budget) {
            // $usedKeys передаём АРГУМЕНТОМ при вызове, а не захватываем в замыкание: порядок
            // B1→B2→B3 важен именно потому, что каждый следующий обязан видеть ключ, добавленный
            // предыдущим (иначе, скажем, B2 и B3 могли бы задедуплиться друг с другом только
            // случайно).
            foreach ([
                fn (array $keys): ?array => $this->foundingYearBit($brand, $keys),
                fn (array $keys): ?array => $this->categoriesBit($brand, $keys),
                fn (array $keys): ?array => $this->materialBit($brand, $keys),
            ] as $attempt) {
                if (count($bits) >= $budget) {
                    break;
                }
                $result = $attempt($usedKeys);
                if ($result === null) {
                    continue;
                }
                [$text, $key] = $result;
                $usedKeys[$key] = true;
                $bits[] = $text;
                $detCount++;
            }

            // Пара про комиссию маркетплейса — ТОЛЬКО если после неё не остаётся дырки на один
            // бит (одна половина пары без другой не несёт смысла) и только последними битами.
            $remaining = $budget - count($bits);
            if ($remaining >= 2) {
                $marketplaceKey = $this->dedupKey(self::MARKETPLACE_BIT);
                $oursKey = $this->dedupKey(self::OURS_BIT);
                if (!isset($usedKeys[$marketplaceKey]) && !isset($usedKeys[$oursKey])) {
                    $bits[] = self::MARKETPLACE_BIT;
                    $bits[] = self::OURS_BIT;
                    $detCount += 2;
                }
            }
        }

        return ['bits' => $bits, 'ragCount' => $ragCount, 'detCount' => $detCount];
    }

    private function bitsSourceKey(int $ragCount, int $detCount, int $total): string
    {
        if ($total === 0) {
            return 'none';
        }
        if ($detCount === 0) {
            return 'rag' . $total;
        }
        if ($ragCount === 0) {
            return 'det' . $total;
        }

        return 'mix' . $total;
    }

    /** @return array{0:string,1:string}|null [текст, дедуп-ключ] */
    private function foundingYearBit(Brand $brand, array $usedKeys): ?array
    {
        $year = trim((string) $brand->getFoundingYear());
        if (!preg_match('/^(19|20)\d{2}$/', $year)) {
            return null;
        }

        $text = "Основан в {$year}.";
        $key = $this->dedupKey($text);

        return isset($usedKeys[$key]) ? null : [$text, $key];
    }

    /** @return array{0:string,1:string}|null */
    private function categoriesBit(Brand $brand, array $usedKeys): ?array
    {
        $categories = $this->attributes->findValuesByBrandAndName($brand, BrandAttribute::NAME_CATEGORY);
        if ($categories === []) {
            return null;
        }

        $chosen = [];
        foreach ($categories as $category) {
            if (count($chosen) >= 3) {
                break;
            }
            $candidate = [...$chosen, $category];
            $text = $this->capitalize(implode(', ', $candidate)) . '.';
            if (!$this->fitsLine($text)) {
                break;
            }
            $chosen = $candidate;
        }

        if ($chosen === []) {
            return null;
        }

        $text = $this->capitalize(implode(', ', $chosen)) . '.';
        $key = $this->dedupKey($text);

        return isset($usedKeys[$key]) ? null : [$text, $key];
    }

    /** @return array{0:string,1:string}|null */
    private function materialBit(Brand $brand, array $usedKeys): ?array
    {
        foreach ($this->attributes->findValuesByBrandAndName($brand, BrandAttribute::NAME_MATERIAL) as $value) {
            $material = mb_strtolower(trim($value));
            if (!in_array($material, self::MATERIAL_WHITELIST, true)) {
                continue;
            }

            $text = 'Ткань — ' . $material . '.';
            if (!$this->fitsLine($text)) {
                continue;
            }

            $key = $this->dedupKey($text);
            if (isset($usedKeys[$key])) {
                continue;
            }

            return [$text, $key];
        }

        return null;
    }

    // --- Grounded-биты (LLM) ---------------------------------------------------------------

    /** @return list<string> валидированные кандидаты, отсортированные по убыванию скора */
    private function groundedCandidates(Brand $brand): array
    {
        try {
            $context = $this->rag->retrieve($brand)['context'] ?? null;
        } catch (\Throwable) {
            $context = null;
        }
        if ($context === null) {
            return [];
        }

        try {
            $raw = $this->llm->generate($this->bitPrompt($context), self::BIT_SYSTEM_PROMPT, local: true, think: false);
        } catch (\Throwable) {
            return [];
        }

        $scored = [];
        foreach (preg_split('/\R/u', trim($raw)) ?: [] as $line) {
            $candidate = $this->normalizeBitLine($line);
            if ($candidate === null) {
                continue;
            }
            if (!$this->passesGrounding($candidate, $context, $brand)) {
                continue;
            }
            if ($this->isFillerLine($candidate)) {
                continue;
            }
            $scored[] = ['text' => $candidate, 'score' => $this->scoreBit($candidate, $context, $brand)];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_column($scored, 'text');
    }

    private function bitPrompt(string $context): string
    {
        return <<<EOT
Выдержки о бренде:

{$context}

Выпиши 8 конкретных фактов об этом бренде — каждый отдельной строкой, без нумерации.

Правила для каждой строки:
- не длиннее 20 знаков вместе с пробелами;
- законченная мысль;
- только то, что прямо сказано в выдержках — ничего от себя;
- не называть сам бренд по имени;
- без оценочных слов: уникальный, инновационный, качественный и подобных;
- без кавычек, эмодзи, хэштегов, ссылок;
- цифры — только те, что есть в выдержках.

Образец тона (это ПРИМЕР СТИЛЯ, не факты об этом бренде):
Начинали для себя.
Ткань — только футер.
Первый цех — гараж.
Название — фамилия.
EOT;
    }

    /**
     * Провал любой ступени = кандидат насовсем выброшен, без ретрая: подгонка текста под
     * правила рискованнее, чем потерять один факт из восьми присланных моделью.
     */
    private function normalizeBitLine(string $raw): ?string
    {
        $line = trim($raw);
        $line = (string) preg_replace('/^[\-\*•\d]+[.\)]?\s*/u', '', $line);
        $line = trim($line, " \t\n\r\0\x0B«»\"'");
        if ($line === '') {
            return null;
        }
        if (preg_match('/[.!?]$/u', $line) !== 1) {
            $line .= '.';
        }

        if (mb_strlen($line) > SlideScript::MAX_LINE_CHARS) {
            return null;
        }
        if (!$this->fitsLine($line)) {
            return null;
        }
        if (preg_match('/[«»"\'#@…\/*_`\[\]]/u', $line) === 1 || mb_stripos($line, 'http') !== false) {
            return null;
        }
        foreach ($this->validator->getAiPhrases() as $phrase) {
            if (mb_stripos($line, $phrase) !== false) {
                return null;
            }
        }
        if ($this->validator->findGlitchCandidateWords($line) !== []) {
            return null;
        }

        return $line;
    }

    /**
     * Заземление на выдержках: каждая цифровая группа — дословно из контекста либо год
     * основания; каждое слово ≥5 букв — его 5-префикс есть в контексте; непроверяемые
     * претензии («шьют», «эксклюзивно» …) — только если их корень тоже есть в контексте.
     */
    private function passesGrounding(string $line, string $context, Brand $brand): bool
    {
        $normalizedContext = $this->normalize($context);

        if (preg_match_all('/\d+/', $line, $digits) > 0) {
            $foundingYear = trim((string) $brand->getFoundingYear());
            foreach ($digits[0] as $digit) {
                if ($digit === $foundingYear) {
                    continue;
                }
                if (mb_stripos($context, $digit) === false) {
                    return false;
                }
            }
        }

        preg_match_all('/\p{L}+/u', $line, $words);
        foreach ($words[0] as $word) {
            if (mb_strlen($word) < 5) {
                continue;
            }
            $prefix = $this->normalize(mb_substr($word, 0, 5));
            if (mb_stripos($normalizedContext, $prefix) === false) {
                return false;
            }
        }

        if (preg_match_all(self::CLAIM_PATTERN, $line, $claims) > 0) {
            foreach ($claims[0] as $claim) {
                $prefix = $this->normalize(mb_substr($claim, 0, 5));
                if (mb_stripos($normalizedContext, $prefix) === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Строка из одних общих слов каталога («это бренд одежды») — не факт, а филлер. */
    private function isFillerLine(string $line): bool
    {
        preg_match_all('/\p{L}+/u', $line, $matches);
        $contentWords = array_filter($matches[0], static fn (string $w): bool => mb_strlen($w) >= 4);
        if ($contentWords === []) {
            return true;
        }

        foreach ($contentWords as $word) {
            $lower = mb_strtolower($word);
            $isFiller = false;
            foreach (self::FILLER_ROOTS as $root) {
                if (str_starts_with($lower, $root) || str_starts_with($root, $lower)) {
                    $isFiller = true;
                    break;
                }
            }
            if (!$isFiller) {
                return false;
            }
        }

        return true;
    }

    /**
     * +2 год из контекста, +1 слово из категорий/материалов бренда (топикальность факта),
     * +1 короче 16 знаков (влезает с запасом), −1 начинается с «Это»/«Они» (обезличенный
     * пересказ хуже прямого факта).
     */
    private function scoreBit(string $line, string $context, Brand $brand): int
    {
        $score = 0;

        if (preg_match('/\b(19|20)\d{2}\b/', $line, $m) === 1 && mb_stripos($context, $m[0]) !== false) {
            $score += 2;
        }

        $normalizedLine = $this->normalize($line);
        foreach ($this->brandVocabulary($brand) as $word) {
            $prefix = $this->normalize(mb_substr($word, 0, 5));
            if ($prefix !== '' && mb_stripos($normalizedLine, $prefix) !== false) {
                $score += 1;
                break;
            }
        }

        if (mb_strlen($line) <= 16) {
            $score += 1;
        }

        if (preg_match('/^(Это|Они)\b/u', $line) === 1) {
            $score -= 1;
        }

        return $score;
    }

    /** @return list<string> категории + материалы бренда — словарь для scoreBit() */
    private function brandVocabulary(Brand $brand): array
    {
        return [
            ...$this->attributes->findValuesByBrandAndName($brand, BrandAttribute::NAME_CATEGORY),
            ...$this->attributes->findValuesByBrandAndName($brand, BrandAttribute::NAME_MATERIAL),
        ];
    }

    // --- Общие хелперы ------------------------------------------------------------------

    private function fitsLine(string $text): bool
    {
        return mb_strlen($text) <= SlideScript::MAX_LINE_CHARS && $this->budget->fits($text, self::HOOK_FONT_SIZE);
    }

    /**
     * Дедуп-ключ — 5-префикс первого содержательного слова (≥5 букв) строки, нормализованный
     * (нижний регистр, ё→е). Один и тот же ключ у хука и бита/двух битов = повтор смысла на
     * соседних кадрах, второе вхождение выбрасывается.
     */
    private function dedupKey(string $text): string
    {
        preg_match_all('/\p{L}+/u', $text, $matches);
        foreach ($matches[0] as $word) {
            if (mb_strlen($word) >= 5) {
                return $this->normalize(mb_substr($word, 0, 5));
            }
        }

        return $this->normalize($text);
    }

    private function normalize(string $text): string
    {
        return str_replace('ё', 'е', mb_strtolower($text, 'UTF-8'));
    }

    private function capitalize(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }
}
