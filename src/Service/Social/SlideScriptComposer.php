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
 * Сценарий надписей поста-галереи v4 — «ФАКТ ВПЕРЁД»: хук сам обязан быть фактом (или самым
 * сильным фактом о бренде, или платформенным фактом про комиссию маркетплейса), а не
 * метазаходом-загадкой («Угадай город.», «Имя — в конце.») — те форматы владелец закрыл после
 * ревью v3. Собирает: hookA/hookB (кадры 1–2), 0..2 бита-факта (кадры 4,6…) и развязку
 * (последний кадр: имя, «город · категории», просьба сохранить).
 *
 * ВЕТКИ (H1 проверяется первой, дальше — бинарный выбор по наличию годного RAG-факта):
 *  H1 «ушедший бренд» — slug встречается в alternatives departed_brands.yaml, хук не про сам
 *      бренд, а про замену — остаётся отдельным независимо от RAG (не переименован в f-схему);
 *  F1 «rag»  — есть ≥1 LLM-факт, прошедший ужесточённый гейт (см. ниже) И достаточно «плотный»
 *      для хука (isHookAEligible) — hookA = лучший по скору такой факт, hookB фиксирован
 *      («Чей — в конце.» — держит связку с развязкой: имя закрывает вопрос «чей»), биты —
 *      следующие 1-2 факта по скору;
 *  F2 «fee»  — фактов нет (или ни один не тянет на хук) — hookA/hookB фиксированы (платформенный
 *      факт про комиссию маркетплейса), биты — только детерминированный добор год→категории→
 *      материал, БЕЗ пары про маркетплейс на слайдах (комиссия уже сказана в хуке — повторять её
 *      битом означает дублировать смысл на соседних кадрах).
 *
 * БИТЫ. F1 — только grounded (LLM на выдержках BrandRagService, тот же гейт качества, что у
 * FounderStoryCaptionSource). F2/H1-добор — детерминированный: год основания → категории →
 * материал. Каждый LLM-кандидат проходит цепочку guard-проверок (длина/пиксели/запрещённые
 * символы/AI-фразы/глитчи/цифры и слова только из выдержек/пустышки/короткая латиница/
 * анти-слоган/производственные претензии) — провал ЛЮБОЙ ступени выбрасывает кандидата насовсем,
 * без ретрая: подгонять текст под правила опаснее, чем потерять один факт из восьми.
 *
 * scriptKey фиксирует РЕАЛИЗОВАННУЮ ветку + источник битов, напр. 'f1.rag|b.rag2|c.save' —
 * по нему SocialGenerateCommand решает held (F1 — ручной просмотр первой партии, см. doc там) vs
 * scheduled, и app:social:evaluate группирует closed-loop.
 */
class SlideScriptComposer
{
    private const HOOK_FONT_SIZE = 54;

    private const DEPARTED_HOOK_B = 'Но не копия.';

    /** F1: связка с развязкой обязана сохраниться — имя бренда на последнем кадре отвечает «чей». */
    private const RAG_HOOK_B = 'Чей — в конце.';

    /**
     * F2. Длинная формулировка (25 знаков) не влезает в пиксельный бюджет хук-плашки на кегле
     * HOOK_FONT_SIZE (963px > 952px, см. testEveryProducedLineFitsPixelBudget) — feeHookA()
     * проверяет это через SlideTextBudget и отдаёт короткую.
     */
    private const FEE_HOOK_A_LONG = 'Маркетплейс берёт до 67%.';
    private const FEE_HOOK_A_SHORT = 'Маркетплейс: до 67%.';
    private const FEE_HOOK_B = 'У этого бренда — 0%.';

    private const FINALE_ASK = 'Сохрани, чтобы не искать.';

    /**
     * H1-фолбэк (см. composeBits()/deterministicBits() doc) — платформенный факт (проверяемый,
     * PillarCaptionSource::PILLARS), но только как пара и только последними битами: разрозненно
     * «мы — 0%» без пары про маркетплейс не несёт смысла. В F2 эта пара не используется — там
     * комиссия уже в хуке.
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
     * Категории мн.ч. для развязки (finaleMeta) — словарь построен по реальному распределению
     * brand_attribute.category (SELECT value, COUNT(*) ... GROUP BY value, июль 2026): бо́льшая
     * часть значений в БД уже во мн.ч. (identity-записи), но заметная часть — в ед.ч. («футболка»,
     * «платье», «куртка» …) и именно они давали враньё вида «Стерлитамак · футболка» под роликом
     * с десятком фото курток. Категория не из словаря — пропускается (finaleCategoriesText()), а
     * не гадается по морфологии.
     *
     * @var array<string,string>
     */
    private const CATEGORY_PLURAL = [
        'брюки' => 'брюки', 'футболки' => 'футболки', 'платья' => 'платья',
        'аксессуары' => 'аксессуары', 'шорты' => 'шорты', 'юбки' => 'юбки',
        'рубашки' => 'рубашки', 'топы' => 'топы', 'сумки' => 'сумки',
        'верхняя одежда' => 'верхняя одежда', 'костюмы' => 'костюмы', 'обувь' => 'обувь',
        'джинсы' => 'джинсы', 'худи' => 'худи', 'куртки' => 'куртки', 'толстовки' => 'толстовки',
        'жакеты' => 'жакеты', 'жилеты' => 'жилеты', 'пальто' => 'пальто',
        'нижнее бельё' => 'нижнее бельё', 'одежда' => 'одежда', 'свитшоты' => 'свитшоты',
        'лонгсливы' => 'лонгсливы', 'комбинезоны' => 'комбинезоны', 'блузы' => 'блузы',
        'кардиганы' => 'кардиганы', 'штаны' => 'штаны', 'джемперы' => 'джемперы',
        'кофты' => 'кофты', 'ботинки' => 'ботинки', 'кроссовки' => 'кроссовки',
        'пиджаки' => 'пиджаки', 'майки' => 'майки', 'блузки' => 'блузки', 'туфли' => 'туфли',
        'купальники' => 'купальники', 'сандалии' => 'сандалии', 'поло' => 'поло',
        'рюкзаки' => 'рюкзаки', 'боди' => 'боди', 'кеды' => 'кеды', 'трикотаж' => 'трикотаж',
        'свитеры' => 'свитеры', 'свитера' => 'свитера', 'пуховики' => 'пуховики',
        'носки' => 'носки', 'сарафаны' => 'сарафаны', 'шапки' => 'шапки', 'сапоги' => 'сапоги',
        'мистери боксы' => 'мистери боксы', 'босоножки' => 'босоножки', 'лоферы' => 'лоферы',
        'шарфы' => 'шарфы', 'леггинсы' => 'леггинсы', 'женская одежда' => 'женская одежда',
        'головные уборы' => 'головные уборы', 'водолазки' => 'водолазки',
        'перчатки' => 'перчатки', 'балетки' => 'балетки', 'парфюмерия' => 'парфюмерия',
        'бомберы' => 'бомберы', 'плащи' => 'плащи', 'кошельки' => 'кошельки',
        'кепки' => 'кепки', 'ботильоны' => 'ботильоны', 'ветровки' => 'ветровки',
        'пижамы' => 'пижамы', 'ремни' => 'ремни', 'трусы' => 'трусы', 'браслеты' => 'браслеты',
        'сыворотки' => 'сыворотки', 'комплекты' => 'комплекты', 'маски' => 'маски',
        'туники' => 'туники', 'серьги' => 'серьги', 'украшения' => 'украшения', 'сабо' => 'сабо',
        'халаты' => 'халаты', 'джоггеры' => 'джоггеры',
        // ед.ч. → мн.ч. — источник бага
        'футболка' => 'футболки', 'платье' => 'платья', 'юбка' => 'юбки',
        'рубашка' => 'рубашки', 'лонгслив' => 'лонгсливы', 'джемпер' => 'джемперы',
        'куртка' => 'куртки', 'свитшот' => 'свитшоты', 'топ' => 'топы', 'костюм' => 'костюмы',
        'блуза' => 'блузы',
    ];

    /**
     * Слова-корни непроверяемых претензий («шьют вручную», «эксклюзивно», «дешевле», «свои
     * лекала») — пропускаются только если тот же корень дословно есть в выдержках бренда.
     * «цех(?!ов)» — «цех»/«цеха» ловим, «цехов» (обобщённая claim-конструкция без привязки к
     * конкретному цеху бренда) — нет.
     */
    private const CLAIM_PATTERN = '/(шьют|шьёт|шили|сшит\w*|ручн\w*|вручную|дешев\w*|дёшев\w*|лимит\w*|эксклюз\w*|лучш\w*|единствен\w*|только\s+у\s+нас|лекал|крой|пошив|цех(?!ов)|производств)/iu';

    /** Строка, начинающаяся с одного из этих предлогов, без цифры и без существительного бренда — слоган, см. isAntiSlogan(). */
    private const ANTI_SLOGAN_START = '/^(Для|Во|Ради|К|На)\b/u';

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

    public function compose(Brand $brand, int $totalSlides, string $durationsProfile = SlideScript::PROFILE_FLAT): SlideScript
    {
        $budget = SlideScript::maxBits($totalSlides);

        $departed = $this->departedMatch($brand);
        $departedHookA = $departed !== null ? 'Вместо ' . $departed['departed'] . '?' : null;
        $script = $departedHookA !== null && $this->fitsLine($departedHookA)
            ? $this->composeDeparted($brand, $budget, $departedHookA, $durationsProfile)
            : $this->composeFactBranch($brand, $budget, $durationsProfile);

        return $this->enforceScriptGuards($script, $brand, $budget, $durationsProfile);
    }

    /**
     * P0-5 (§9 №5 плейбука) — двойная защита от класса провала `Da7Ocn1MllA` (12storeez, строб
     * без единого текстового объекта и без речи → ×0.99 медианы, LR ниже нормы аккаунта):
     *
     * (а) hookA пуст — у нас нет ни голоса, ни аномального материала, поэтому плашка первого
     *     кадра это и есть весь «голос» ролика; фолбэк на F2 (единственная ветка, собираемая
     *     всегда, без RAG/departed);
     * (б) hookA/hookB заканчивается «?» — незакрытый вопрос обязан получить ответ в развязке
     *     (для H1 «Вместо {X}?» ответ — имя бренда), иначе связка живёт только в подписи, как
     *     у `Da7Ocn1MllA` («мечта × ракета» была только в тексте, а не в кадре).
     */
    private function enforceScriptGuards(SlideScript $script, Brand $brand, int $budget, string $durationsProfile): SlideScript
    {
        if (trim($script->hookA) === '') {
            return $this->composeFeeFallback($brand, $budget, $durationsProfile);
        }

        $endsWithQuestion = str_ends_with(trim($script->hookA), '?') || str_ends_with(trim($script->hookB), '?');
        if ($endsWithQuestion && trim($script->finaleTitle) === '') {
            throw new \RuntimeException('Хук заканчивается вопросом, но развязка не даёт ответа (finaleTitle пуст) — P0-5(б), §9 плейбука.');
        }

        return $script;
    }

    // --- H1: ушедший бренд ---------------------------------------------------------------

    private function composeDeparted(Brand $brand, int $budget, string $hookA, string $durationsProfile): SlideScript
    {
        $hookB = self::DEPARTED_HOOK_B;
        $usedKeys = [$this->dedupKey($hookA) => true, $this->dedupKey($hookB) => true];

        ['bits' => $bits, 'ragCount' => $ragCount, 'detCount' => $detCount] = $this->composeBits($brand, $budget, $usedKeys);

        return new SlideScript(
            hookA: $hookA,
            hookB: $hookB,
            bits: $bits,
            finaleTitle: trim((string) $brand->getTitle()),
            finaleMeta: $this->finaleMeta($brand),
            finaleAsk: self::FINALE_ASK,
            scriptKey: sprintf('h1.departed|b.%s|c.save', $this->bitsSourceKey($ragCount, $detCount, count($bits))),
            durationsProfile: $durationsProfile,
        );
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

    // --- F1/F2: факт вперёд ----------------------------------------------------------------

    /**
     * F1, если хотя бы один grounded-кандидат достаточно «плотный» для хука
     * (isHookAEligible — совсем телеграфные обрывки в хук не пускаем, хотя в биты они годятся),
     * иначе F2. hookA в F1 — ЛУЧШИЙ по скору из подходящих (не обязательно candidates[0], если
     * тот не прошёл hookA-порог); в биты идут все остальные кандидаты по убыванию скора.
     */
    private function composeFactBranch(Brand $brand, int $budget, string $durationsProfile): SlideScript
    {
        $candidates = $this->dedupCandidates($this->groundedCandidates($brand));

        $hookIndex = null;
        foreach ($candidates as $i => $candidate) {
            if ($this->isHookAEligible($candidate)) {
                $hookIndex = $i;
                break;
            }
        }

        if ($hookIndex === null) {
            return $this->composeFeeFallback($brand, $budget, $durationsProfile);
        }

        $hookA = $candidates[$hookIndex];
        unset($candidates[$hookIndex]);
        $bits = array_slice(array_values($candidates), 0, min(2, $budget));

        return new SlideScript(
            hookA: $hookA,
            hookB: self::RAG_HOOK_B,
            bits: $bits,
            finaleTitle: trim((string) $brand->getTitle()),
            finaleMeta: $this->finaleMeta($brand),
            finaleAsk: self::FINALE_ASK,
            scriptKey: sprintf('f1.rag|b.%s|c.save', $this->bitsSourceKey(count($bits), 0, count($bits))),
            durationsProfile: $durationsProfile,
        );
    }

    /** F2 — фактов нет вообще (или ни один не тянет на хук): единственная ветка, собираемая без
     *  RAG/departed, поэтому это и фолбэк enforceScriptGuards() при пустом hookA (P0-5а). */
    private function composeFeeFallback(Brand $brand, int $budget, string $durationsProfile): SlideScript
    {
        $hookA = $this->feeHookA();
        $hookB = self::FEE_HOOK_B;
        $usedKeys = [$this->dedupKey($hookA) => true, $this->dedupKey($hookB) => true];
        ['bits' => $bits, 'count' => $detCount] = $this->deterministicBits($brand, $budget, $usedKeys);

        return new SlideScript(
            hookA: $hookA,
            hookB: $hookB,
            bits: $bits,
            finaleTitle: trim((string) $brand->getTitle()),
            finaleMeta: $this->finaleMeta($brand),
            finaleAsk: self::FINALE_ASK,
            scriptKey: sprintf('f2.fee|b.%s|c.save', $this->bitsSourceKey(0, $detCount, count($bits))),
            durationsProfile: $durationsProfile,
        );
    }

    /**
     * Хук-открывашка не может быть телеграфным обрывком: ≤20 знаков (жёстче общего лимита в 22 —
     * хук держит внимание один кадр, без соседних слов бита) И (хотя бы одно слово ≥6 букв ИЛИ
     * есть цифра — конкретика). Кандидат, не прошедший это, всё ещё годится в биты — там порог
     * ниже (общий гейт groundedCandidates()).
     */
    private function isHookAEligible(string $line): bool
    {
        if (mb_strlen($line) > 20) {
            return false;
        }
        if (preg_match('/\d/', $line) === 1) {
            return true;
        }

        preg_match_all('/\p{L}+/u', $line, $words);
        foreach ($words[0] as $word) {
            if (mb_strlen($word) >= 6) {
                return true;
            }
        }

        return false;
    }

    /** См. константу FEE_HOOK_A_LONG/SHORT. */
    private function feeHookA(): string
    {
        return $this->budget->fits(self::FEE_HOOK_A_LONG, self::HOOK_FONT_SIZE)
            ? self::FEE_HOOK_A_LONG
            : self::FEE_HOOK_A_SHORT;
    }

    /**
     * Первый кандидат с каждым уникальным dedup-ключом (candidates уже отсортированы по убыванию
     * скора groundedCandidates()) — иначе «Ткань — шерсть.» и «Ткань очень мягкая.» заняли бы
     * обе позиции hookA/бит одним и тем же смыслом.
     *
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    private function dedupCandidates(array $candidates): array
    {
        $result = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $key = $this->dedupKey($candidate);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $candidate;
        }

        return $result;
    }

    // --- Развязка -------------------------------------------------------------------------

    /**
     * «{Город} · {категория1} и {категория2}», ≤30 знаков. Категории попадают в мету ТОЛЬКО
     * парой (finaleCategoriesText()) — одна категория хуже нуля: под роликом с десятком фото
     * курток строка «Стерлитамак · футболка» врёт про единственную вещь в ассортименте (ревью).
     */
    private function finaleMeta(Brand $brand): string
    {
        $city = trim((string) $brand->getCity());
        $categories = $this->finaleCategoriesText($brand, $city);

        if ($categories !== null) {
            return $city !== '' ? $city . ' · ' . $categories : $this->capitalize($categories);
        }

        return $city !== '' ? $city : 'Российский бренд';
    }

    /**
     * «категория1 и категория2» — только известные словарю CATEGORY_PLURAL (неизвестное слово
     * пропускается, а не гадается) и только если пара целиком влезает в бюджет вместе с городом;
     * меньше двух известных категорий — null (мета остаётся без категорий, только город).
     */
    private function finaleCategoriesText(Brand $brand, string $city): ?string
    {
        $known = [];
        foreach ($this->attributes->findValuesByBrandAndName($brand, BrandAttribute::NAME_CATEGORY) as $value) {
            $plural = self::CATEGORY_PLURAL[mb_strtolower(trim($value))] ?? null;
            if ($plural === null) {
                continue;
            }
            $known[] = $plural;
            if (count($known) >= 2) {
                break;
            }
        }

        if (count($known) < 2) {
            return null;
        }

        $text = $known[0] . ' и ' . $known[1];
        $full = $city !== '' ? $city . ' · ' . $text : $this->capitalize($text);

        return mb_strlen($full) <= SlideScript::FINALE_META_MAX_CHARS ? $text : null;
    }

    // --- Биты -------------------------------------------------------------------------------

    /**
     * H1-путь: grounded-кандидаты + детерминированный добор (год→категории→материал), и —
     * только если после добора остаётся дырка ровно на пару слотов — платформенная пара про
     * маркетплейс (MARKETPLACE_BIT/OURS_BIT). Используется только composeDeparted(): в F1/F2
     * (composeFactBranch()) хук уже сам факт, добор — deterministicBits() без пары.
     *
     * @param array<string,bool> $usedKeys дедуп-ключи, уже занятые хуком
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

        ['bits' => $detBits, 'count' => $detCount, 'usedKeys' => $usedKeys] = $this->deterministicBits($brand, $budget - count($bits), $usedKeys);
        $bits = [...$bits, ...$detBits];

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

        return ['bits' => $bits, 'ragCount' => $ragCount, 'detCount' => $detCount];
    }

    /**
     * B1→B2→B3: год основания → категории → материал, жадно до $slotsLeft. Порядок важен —
     * каждый следующий обязан видеть ключ, добавленный предыдущим (иначе, скажем, B2 и B3 могли
     * бы задедуплиться друг с другом только случайно).
     *
     * @param array<string,bool> $usedKeys
     *
     * @return array{bits:list<string>,count:int,usedKeys:array<string,bool>}
     */
    private function deterministicBits(Brand $brand, int $slotsLeft, array $usedKeys): array
    {
        $bits = [];
        foreach ([
            fn (array $keys): ?array => $this->foundingYearBit($brand, $keys),
            fn (array $keys): ?array => $this->categoriesBit($brand, $keys),
            fn (array $keys): ?array => $this->materialBit($brand, $keys),
        ] as $attempt) {
            if (count($bits) >= $slotsLeft) {
                break;
            }
            $result = $attempt($usedKeys);
            if ($result === null) {
                continue;
            }
            [$text, $key] = $result;
            $usedKeys[$key] = true;
            $bits[] = $text;
        }

        return ['bits' => $bits, 'count' => count($bits), 'usedKeys' => $usedKeys];
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
            if ($this->hasUngroundedShortLatinToken($candidate, $context, $brand)) {
                continue;
            }
            if ($this->isAntiSlogan($candidate, $brand)) {
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
     * претензии («шьют», «эксклюзивно», «свои лекала» …) — только если их корень тоже есть в
     * контексте.
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

    /**
     * Латинские обрывки ≤4 знаков («AM», «PRO», «XZ») зритель не считывает за 1.5с. Токен вне
     * контекста — брак целиком; токен ≤3 букв («AM») — брак ДАЖЕ если дословно есть в выдержках
     * (аббревиатура всё равно нечитаема), кроме слов из словаря категорий/материалов ЭТОГО
     * бренда (brandVocabulary()).
     */
    private function hasUngroundedShortLatinToken(string $line, string $context, Brand $brand): bool
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $line, $tokens);
        foreach ($tokens[0] as $token) {
            if (preg_match('/[A-Za-z]/', $token) !== 1) {
                continue;
            }
            if (mb_strlen($token) > 4) {
                continue;
            }
            if (!str_contains($context, $token)) {
                return true;
            }
            if (mb_strlen($token) <= 3 && !$this->isKnownVocabularyWord($token, $brand)) {
                return true;
            }
        }

        return false;
    }

    private function isKnownVocabularyWord(string $token, Brand $brand): bool
    {
        $needle = mb_strtolower($token);
        foreach ($this->brandVocabulary($brand) as $word) {
            preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($word), $wordTokens);
            if (in_array($needle, $wordTokens[0], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Анти-слоган: строка без единой цифры И без существительного из словаря бренда
     * (категории/материалы/город) И начинающаяся с предлога («Для», «Во», «Ради», «К», «На») —
     * слоган, а не факт («Для тех, кто ценит стиль.»), даже если формально прошла заземление
     * (предлоги короче 5 букв — 5-префиксная проверка их не ловит).
     */
    private function isAntiSlogan(string $line, Brand $brand): bool
    {
        if (preg_match('/\d/', $line) === 1) {
            return false;
        }
        if ($this->containsBrandNoun($line, $brand)) {
            return false;
        }

        return preg_match(self::ANTI_SLOGAN_START, trim($line)) === 1;
    }

    private function containsBrandNoun(string $line, Brand $brand): bool
    {
        $normalizedLine = $this->normalize($line);
        $dictionary = $this->brandVocabulary($brand);
        $city = trim((string) $brand->getCity());
        if ($city !== '') {
            $dictionary[] = $city;
        }

        foreach ($dictionary as $word) {
            $word = trim($word);
            if ($word === '') {
                continue;
            }
            $needle = mb_strlen($word) >= 5 ? $this->normalize(mb_substr($word, 0, 5)) : $this->normalize($word);
            if ($needle !== '' && mb_stripos($normalizedLine, $needle) !== false) {
                return true;
            }
        }

        return false;
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
     * +2 год из контекста, +1 наличие любой цифры (конкретика вперёд — v4), +1 слово из
     * категорий/материалов бренда (топикальность факта), +1 короче 16 знаков (влезает с
     * запасом), −1 начинается с «Это»/«Они» (обезличенный пересказ хуже прямого факта).
     */
    private function scoreBit(string $line, string $context, Brand $brand): int
    {
        $score = 0;

        if (preg_match('/\b(19|20)\d{2}\b/', $line, $m) === 1 && mb_stripos($context, $m[0]) !== false) {
            $score += 2;
        }

        if (preg_match('/\d/', $line) === 1) {
            $score += 1;
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

    /** @return list<string> категории + материалы бренда — словарь для scoreBit()/гейта латиницы/анти-слогана */
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
