<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Brand;
use App\Entity\BrandAttribute;
use App\Repository\BrandAttributeRepository;
use App\Service\BrandRagService;
use App\Service\ContentValidator;
use App\Service\LlmService;
use App\Service\Social\SlideScript;
use App\Service\Social\SlideScriptComposer;
use App\Service\Social\SlideTextBudget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * v4 «ФАКТ ВПЕРЁД»: H1 (ушедший бренд, не тронут) → бинарный выбор F1 (grounded LLM-факт ведёт
 * хук) / F2 (фактов нет — хук фиксирован на комиссии маркетплейса), детерминированный «добор»
 * (год/категории/материал) и развязка с именем. Ядро проверок: правильная ветка выигрывает,
 * ужесточённый гейт (латиница-обрывки/анти-слоганы/производственные claim'ы без глагола)
 * реально выбрасывает невалидные кандидаты, а НИ ОДНА константная строка не переполняет
 * пиксельный бюджет плашки (SlideTextBudget) — иначе она молча ломает ВСЕ посты сразу, а не
 * один конкретный бренд.
 */
class SlideScriptComposerTest extends TestCase
{
    private const DEPARTED_YAML_EMPTY = <<<'YAML'
-
  departed: "Zara"
  alternatives: ["some-other-brand"]
YAML;

    public function testDepartedStageWinsAndFillsWithMarketplacePair(): void
    {
        $yaml = <<<'YAML'
-
  departed: "Zara"
  alternatives: ["our-brand"]
YAML;

        $script = $this->composer(departedYaml: $yaml)
            ->compose($this->brand(slug: 'our-brand'), totalSlides: 7); // budget=2

        self::assertSame('Вместо Zara?', $script->hookA);
        self::assertSame('Но не копия.', $script->hookB);
        self::assertSame(['Маркетплейс — 30–67%.', 'Мы — 0%.'], $script->bits);
        self::assertSame('h1.departed|b.det2|c.send', $script->scriptKey);
    }

    /** Имя ушедшего не влезает в плашку → H1 пропускается, дальше — бинарная ветка F1/F2. */
    public function testDepartedNameTooLongFallsBackToFactBranch(): void
    {
        $yaml = <<<'YAML'
-
  departed: "Очень-очень-длинное-название-бренда-которое-никогда-не-влезет"
  alternatives: ["our-brand"]
YAML;

        $script = $this->composer(departedYaml: $yaml)
            ->compose($this->brand(slug: 'our-brand', city: 'Пермь'), totalSlides: 7);

        // Нет RAG-фактов у этого бренда → F2 (город больше не выбирает отдельную ступень хука).
        self::assertSame('Маркетплейс: до 67%.', $script->hookA);
        self::assertStringStartsWith('f2.fee|', $script->scriptKey);
    }

    public function testRagBranchWinsWithGroundedFacts(): void
    {
        $context = 'Бренд начинали для себя в 2015 году. Первый цех — обычный гараж. '
            . 'Ткань — только футер. Название — фамилия основателя.';
        $llmOutput = "Начинали для себя.\nПервый цех — гараж.\nНазвание — фамилия.\nОснован в 1990.";

        $script = $this->composer(ragContext: $context, llmOutput: $llmOutput)
            ->compose($this->brand(), totalSlides: 7); // budget=2

        // Лучший (первый hookA-годный) факт ведёт хук, связка с развязкой фиксирована.
        self::assertSame('Начинали для себя.', $script->hookA);
        self::assertSame('Чей — в конце.', $script->hookB);
        self::assertSame(['Первый цех — гараж.', 'Название — фамилия.'], $script->bits);
        // «Основан в 1990.» — год НЕ упомянут в выдержках (там 2015) → выброшен без ретрая.
        self::assertNotContains('Основан в 1990.', $script->bits);
        self::assertStringStartsWith('f1.rag|b.rag2|c.save', $script->scriptKey);
    }

    public function testFeeBranchIsUltimateFallbackWithNoFacts(): void
    {
        $script = $this->composer()->compose($this->brand(), totalSlides: 4); // нет RAG/года/категорий/материала

        // Длинная формулировка (25 знаков) не влезает в пиксельный бюджет хук-плашки — короткая.
        self::assertSame('Маркетплейс: до 67%.', $script->hookA);
        self::assertSame('У этого бренда — 0%.', $script->hookB);
        self::assertSame([], $script->bits);
        self::assertSame('f2.fee|b.none|c.save', $script->scriptKey);
    }

    /** Комиссия уже сказана в хуке F2 — на слайдах-битах её больше не повторяем (в отличие от H1). */
    public function testFeeBranchNeverRepeatsMarketplacePairAsBits(): void
    {
        $script = $this->composer()->compose($this->brand(), totalSlides: 7); // budget=2, F2

        self::assertStringStartsWith('f2.fee|', $script->scriptKey);
        self::assertSame([], $script->bits);
    }

    // --- Ротация финальной просьбы (FINALE_ASKS) ----------------------------------------------

    /** Две финальные просьбы ротируются по чётности crc32('ask'.slug), независимо от хука. */
    public function testFinaleAskRotatesBySlug(): void
    {
        // crc32('askbrand-4') % 2 === 0 → «Сохрани…»
        $save = $this->composer()->compose($this->brand(slug: 'brand-4'), totalSlides: 4);
        self::assertSame('Сохрани, чтобы не искать.', $save->finaleAsk);
        self::assertStringEndsWith('c.save', $save->scriptKey);

        // crc32('askour-brand') % 2 === 1 → «Отправь…»
        $send = $this->composer()->compose($this->brand(slug: 'our-brand'), totalSlides: 4);
        self::assertSame('Отправь тому, кто ищет.', $send->finaleAsk);
        self::assertStringEndsWith('c.send', $send->scriptKey);
    }

    /**
     * Ротация двух F2-хуков по чётности slug (useRealHook): бренды без RAG-фактов больше не
     * получают все до одного комиссионный хук — половина ленты идёт с позиционным тезисом
     * «реальные фото» (H8 плейбука). Ветку решает только содержимое, рандома нет.
     */
    public function testF2FallbackRotatesBetweenFeeAndRealBySlugParity(): void
    {
        // crc32('brand-4') % 2 === 1 → ветка f2.real
        $real = $this->composer()->compose($this->brand(slug: 'brand-4'), totalSlides: 7);
        self::assertSame('Это не нейронка.', $real->hookA);
        self::assertSame('Фото сняли они.', $real->hookB);
        self::assertStringStartsWith('f2.real|', $real->scriptKey);

        // crc32('marka-4') % 2 === 0 → контроль f2.fee, как раньше
        $fee = $this->composer()->compose($this->brand(slug: 'marka-4'), totalSlides: 7);
        self::assertSame('Маркетплейс: до 67%.', $fee->hookA);
        self::assertStringStartsWith('f2.fee|', $fee->scriptKey);
    }

    /** Пустой slug не должен улетать в неожиданную ветку — чётность нуля = контроль f2.fee. */
    public function testEmptySlugKeepsFeeBranch(): void
    {
        $script = $this->composer()->compose($this->brand(), totalSlides: 4);

        self::assertSame('Маркетплейс: до 67%.', $script->hookA);
        self::assertStringStartsWith('f2.fee|', $script->scriptKey);
    }

    /** Повторный generate того же бренда обязан дать ту же ветку — ротация детерминирована. */
    public function testRealBranchIsStableAcrossRegeneration(): void
    {
        $composer = $this->composer();
        $brand = $this->brand(slug: 'brand-4');

        $first = $composer->compose($brand, 7);
        $second = $composer->compose($brand, 7);

        self::assertEquals($first, $second);
    }

    /**
     * Цифра, которой нет в выдержках (и не год основания) — кандидат выброшен целиком (не
     * доходит до финального списка).
     */
    public function testUngroundedNumberRejectsCandidate(): void
    {
        $context = 'Бренд шьёт футболки без лишних цифр в описании.';
        $llmOutput = 'Продано 5000 штук.';

        $script = $this->composer(ragContext: $context, llmOutput: $llmOutput)
            ->compose($this->brand(), totalSlides: 7);

        self::assertNotContains('Продано 5000 штук.', $script->bits);
        self::assertNotSame('Продано 5000 штук.', $script->hookA);
    }

    /** Строка из одних общих слов каталога — не факт, отбраковывается как филлер. */
    public function testFillerLineIsRejected(): void
    {
        $context = 'Компания продаёт одежду с 2015 года. Первый цех — гараж. Ткань — только футер.';
        $llmOutput = "Это бренд одежды.\nПервый цех — гараж.\nТкань — только футер.";

        $script = $this->composer(ragContext: $context, llmOutput: $llmOutput)
            ->compose($this->brand(), totalSlides: 7);

        self::assertNotSame('Это бренд одежды.', $script->hookA);
        self::assertNotContains('Это бренд одежды.', $script->bits);
        // Оставшиеся два факта реально факты — один стал хуком (F1), второй битом.
        self::assertSame('Первый цех — гараж.', $script->hookA);
        self::assertSame(['Ткань — только футер.'], $script->bits);
    }

    /** Два похожих факта (один и тот же корневой смысл) — второй дедупится. */
    public function testDuplicateBitsAreDeduped(): void
    {
        $context = 'Ткань — только шерсть. Ткань очень мягкая и приятная на ощупь. Первый цех — гараж.';
        $llmOutput = "Ткань — только шерсть.\nТкань очень мягкая.\nПервый цех — гараж.";

        $script = $this->composer(ragContext: $context, llmOutput: $llmOutput)
            ->compose($this->brand(), totalSlides: 9); // budget=3, но дублей быть не должно

        $tkanLines = array_filter(
            [$script->hookA, ...$script->bits],
            static fn (string $b) => str_starts_with($b, 'Ткань'),
        );
        self::assertCount(1, $tkanLines, 'Обе строки про ткань делят один дедуп-ключ — выжить должна одна');
    }

    /** Бит, чей дедуп-ключ совпал с уже выбранным хуком H1, выбрасывается — идёт следующий фолбэк. */
    public function testBitCollidingWithHookKeyIsDropped(): void
    {
        $yaml = <<<'YAML'
-
  departed: "Вместе"
  alternatives: ["our-brand"]
YAML;

        $script = $this->composer(departedYaml: $yaml, categories: ['вместе', 'футболки'], materials: ['хлопок'])
            ->compose($this->brand(slug: 'our-brand'), totalSlides: 7); // H1: hookA='Вместо Вместе?' → ключ 'вмест'

        self::assertSame('Вместо Вместе?', $script->hookA);
        // «Вместе, футболки.» делит 5-префикс с «Вместо» → отброшен целиком, несмотря на валидность.
        self::assertNotContains('Вместе, футболки.', $script->bits);
        self::assertContains('Ткань — хлопок.', $script->bits);
    }

    public function testFoundingYearBitUsedAsDeterministicFallbackInFeeBranch(): void
    {
        $script = $this->composer()->compose($this->brand(foundingYear: '1998'), totalSlides: 7); // budget=2, F2

        self::assertSame(['Основан в 1998.'], $script->bits);
        self::assertSame('f2.fee|b.det1|c.save', $script->scriptKey);
    }

    public function testCategoriesBitPacksGreedilyWithinCharBudget(): void
    {
        $script = $this->composer(categories: ['брюки', 'футболки', 'платья'])
            ->compose($this->brand(), totalSlides: 7);

        // Третья категория переполнила бы строку за 22 знака — greedy останавливается на второй.
        self::assertSame(['Брюки, футболки.'], $script->bits);
    }

    public function testMaterialBitFiltersOutGarbageValues(): void
    {
        $script = $this->composer(materials: ['polyester', 'pongee', 'хлопок'])
            ->compose($this->brand(), totalSlides: 7);

        self::assertSame(['Ткань — хлопок.'], $script->bits);
    }

    /** Пара про комиссию — только в H1 (developer-хук не про неё), и только целиком, не половиной. */
    public function testMarketplacePairAddedOnlyWhenTwoSlotsRemainInDepartedBranch(): void
    {
        $yaml = <<<'YAML'
-
  departed: "Zara"
  alternatives: ["our-brand"]
YAML;

        $withTwoSlots = $this->composer(departedYaml: $yaml)->compose($this->brand(slug: 'our-brand'), totalSlides: 7); // budget=2
        self::assertSame(['Маркетплейс — 30–67%.', 'Мы — 0%.'], $withTwoSlots->bits);

        $withOneSlot = $this->composer(departedYaml: $yaml)->compose($this->brand(slug: 'our-brand'), totalSlides: 5); // budget=1
        self::assertSame([], $withOneSlot->bits, 'Пара маркетплейса не бывает половинчатой');
    }

    public function testFinaleMetaCombinesCityAndCategories(): void
    {
        $script = $this->composer(categories: ['брюки', 'футболки', 'платья'])
            ->compose($this->brand(city: 'Пермь'), totalSlides: 4);

        self::assertSame('Пермь · брюки и футболки', $script->finaleMeta);
        self::assertLessThanOrEqual(SlideScript::FINALE_META_MAX_CHARS, mb_strlen($script->finaleMeta));
    }

    public function testFinaleMetaFallsBackToCategoriesWithoutCity(): void
    {
        $script = $this->composer(categories: ['брюки', 'футболки'])->compose($this->brand(), totalSlides: 4);

        self::assertSame('Брюки и футболки', $script->finaleMeta);
    }

    public function testFinaleMetaUltimateFallbackWithoutAnyData(): void
    {
        $script = $this->composer()->compose($this->brand(), totalSlides: 4);

        self::assertSame('Российский бренд', $script->finaleMeta);
    }

    /**
     * По ревью: «Стерлитамак · футболка» под роликом с десятком фото курток врёт про
     * единственную вещь в ассортименте — одна категория хуже нуля, показываем только город.
     */
    public function testFinaleMetaOmitsCategoriesWhenOnlyOneKnown(): void
    {
        $script = $this->composer(categories: ['куртка'])->compose($this->brand(city: 'Уфа'), totalSlides: 4);

        self::assertSame('Уфа', $script->finaleMeta);
    }

    /** Ед.ч. из brand_attribute («куртка», «футболка») переводится в мн.ч. по словарю — источник бага. */
    public function testFinaleMetaPluralizesKnownSingularCategories(): void
    {
        $script = $this->composer(categories: ['куртка', 'футболка'])->compose($this->brand(city: 'Уфа'), totalSlides: 4);

        self::assertSame('Уфа · куртки и футболки', $script->finaleMeta);
    }

    /** Неизвестное слово словарю пропускается, а не гадается — до следующей известной категории. */
    public function testFinaleMetaSkipsUnknownCategoryWord(): void
    {
        $script = $this->composer(categories: ['неведомая штуковина', 'куртка', 'футболка'])
            ->compose($this->brand(city: 'Уфа'), totalSlides: 4);

        self::assertSame('Уфа · куртки и футболки', $script->finaleMeta);
    }

    public function testFinaleAskIsFixedSaveRequest(): void
    {
        $script = $this->composer()->compose($this->brand(), totalSlides: 4);

        self::assertSame('Сохрани, чтобы не искать.', $script->finaleAsk);
    }

    public function testFinaleTitleIsBrandName(): void
    {
        $script = $this->composer()->compose($this->brand('Моя Марка'), totalSlides: 4);

        self::assertSame('Моя Марка', $script->finaleTitle);
    }

    /**
     * Повторный вызов на тех же данных обязан давать РОВНО тот же сценарий: в v4 нет
     * seed/рандома — только данные бренда решают, а LLM здесь замокан детерминированно.
     */
    public function testComposeIsDeterministicForSameInput(): void
    {
        $composer = $this->composer(categories: ['брюки']);
        $brand = $this->brand(city: 'Тверь', foundingYear: '2010');

        $first = $composer->compose($brand, 7);
        $second = $composer->compose($brand, 7);

        self::assertEquals($first, $second);
    }

    // --- P0-1: профиль длительностей (durationsProfile) --------------------------------------

    /** compose() без явного профиля — контрольная ветка E1 (flat_150), тогдашнее поведение. */
    public function testDefaultDurationsProfileIsFlat(): void
    {
        $script = $this->composer()->compose($this->brand(), totalSlides: 4);

        self::assertSame(SlideScript::PROFILE_FLAT, $script->durationsProfile);
    }

    /** Профиль — сквозной параметр compose(), не зависит от того, какая ветка (H1/F1/F2) выбрана. */
    public function testDurationsProfilePassesThroughToAnyBranch(): void
    {
        $script = $this->composer()->compose($this->brand(), totalSlides: 4, durationsProfile: SlideScript::PROFILE_HOOK_HOLD);

        self::assertSame(SlideScript::PROFILE_HOOK_HOLD, $script->durationsProfile);
    }

    // --- P0-5: валидация сценария (§9 №5 плейбука) --------------------------------------------

    /**
     * (а) Пустой hookA — отрицательный контроль `Da7Ocn1MllA` (ноль текста и ноль голоса → ×0.99
     * медианы): guard откатывается на F2 (единственная ветка, собираемая без RAG/departed),
     * а не публикует ролик без единого текстового объекта на первом кадре.
     */
    public function testEmptyHookAFallsBackToFeeBranch(): void
    {
        $composer = $this->composer();
        $brand = $this->brand();
        $empty = new SlideScript('', 'Чей — в конце.', [], 'Тест', 'Российский бренд', 'Сохрани, чтобы не искать.', 'f1.rag|b.rag0|c.save');

        $method = new \ReflectionMethod($composer, 'enforceScriptGuards');
        /** @var SlideScript $result */
        $result = $method->invoke($composer, $empty, $brand, 2, SlideScript::PROFILE_FLAT);

        self::assertNotSame('', trim($result->hookA));
        self::assertStringStartsWith('f2.fee|', $result->scriptKey);
    }

    /**
     * (б) Хук H1 «Вместо {X}?» заканчивается вопросом — развязка ОБЯЗАНА содержать ответ
     * (finaleTitle = имя бренда). Пустой title (бренд без имени — вырожденный случай) не даёт
     * ответа на кадре, связка держалась бы только в подписи, как у `Da7Ocn1MllA`.
     */
    public function testQuestionHookWithoutFinaleTitleThrows(): void
    {
        $yaml = <<<'YAML'
-
  departed: "Zara"
  alternatives: ["our-brand"]
YAML;

        $this->expectException(\RuntimeException::class);

        $this->composer(departedYaml: $yaml)->compose($this->brand(title: '', slug: 'our-brand'), totalSlides: 7);
    }

    /** Хук БЕЗ вопроса — finaleTitle пустым быть не обязан (guard срабатывает только на «?»). */
    public function testNonQuestionHookWithEmptyFinaleTitleDoesNotThrow(): void
    {
        $script = $this->composer()->compose($this->brand(title: ''), totalSlides: 4);

        self::assertSame('Маркетплейс: до 67%.', $script->hookA);
    }

    // --- Ужесточённый гейт LLM-фактов (v4) --------------------------------------------------

    /** Латинский обрывок ≤4 знаков, которого нет в выдержках дословно, — брак целиком. */
    public function testShortLatinTokenMissingFromContextRejectsCandidateWholesale(): void
    {
        $context = 'Бренд начинали для себя. Есть узнаваемый стиль в коллекции.';
        $llmOutput = "Свой стиль AM.\nНачинали для себя.";

        $script = $this->composer(ragContext: $context, llmOutput: $llmOutput)->compose($this->brand(), 7);

        self::assertNotSame('Свой стиль AM.', $script->hookA);
        self::assertNotContains('Свой стиль AM.', $script->bits);
        self::assertSame('Начинали для себя.', $script->hookA);
    }

    /** Аббревиатура ≤3 букв — брак ДАЖЕ если дословно есть в выдержках (нечитаема за 1.5с). */
    public function testShortLatinAbbreviationRejectedEvenWhenGrounded(): void
    {
        $context = 'Начинали для себя. Здесь работает SPB отдел.';
        $llmOutput = "Тут есть SPB.\nНачинали для себя.";

        $script = $this->composer(ragContext: $context, llmOutput: $llmOutput)->compose($this->brand(), 7);

        self::assertNotSame('Тут есть SPB.', $script->hookA);
        self::assertNotContains('Тут есть SPB.', $script->bits);
        self::assertSame('Начинали для себя.', $script->hookA);
    }

    /** Исключение: латинская аббревиатура — категория/материал ЭТОГО бренда — не бракуется. */
    public function testShortLatinAbbreviationAllowedWhenItIsBrandsOwnCategory(): void
    {
        $context = 'Начинали для себя. Есть размер XL, пользуется спросом.';
        $llmOutput = "Есть размер XL.\nНачинали для себя.";

        $script = $this->composer(ragContext: $context, llmOutput: $llmOutput, categories: ['XL'])
            ->compose($this->brand(), 7);

        self::assertContains('Есть размер XL.', [$script->hookA, ...$script->bits]);
    }

    /** Анти-слоган: без цифры, без существительного бренда, начинается с предлога — брак. */
    public function testAntiSloganWithoutDigitOrKnownNounIsRejected(): void
    {
        $context = 'Бренд начинали для себя. Для тех, кто ценит стиль и качество каждый день.';
        $llmOutput = "Для тех кто ценит.\nНачинали для себя.";

        $script = $this->composer(ragContext: $context, llmOutput: $llmOutput)->compose($this->brand(), 7);

        self::assertNotSame('Для тех кто ценит.', $script->hookA);
        self::assertNotContains('Для тех кто ценит.', $script->bits);
    }

    /** Исключение анти-слогана: строка с цифрой не бракуется, даже начинаясь с предлога. */
    public function testPrepositionLineWithDigitIsNotAntiSlogan(): void
    {
        $context = 'Бренд начинали для себя в 2015 году. Для 2015 года — необычно.';
        $llmOutput = "Для 2015 года.\nНачинали для себя.";

        $script = $this->composer(ragContext: $context, llmOutput: $llmOutput)->compose($this->brand(), 7);

        self::assertContains('Для 2015 года.', [$script->hookA, ...$script->bits]);
    }

    /** Исключение анти-слогана: упоминание известного существительного (город бренда) — не брак. */
    public function testPrepositionLineWithKnownCityIsNotAntiSlogan(): void
    {
        $context = 'Бренд начинали для себя. Бренд родом из Новосибирска.';
        $llmOutput = "Для Новосибирска.\nНачинали для себя.";

        $script = $this->composer(ragContext: $context, llmOutput: $llmOutput)
            ->compose($this->brand(city: 'Новосибирск'), 7);

        self::assertContains('Для Новосибирска.', [$script->hookA, ...$script->bits]);
    }

    /** Производственный claim без глагола («свой крой») требует того же 5-префиксного заземления. */
    public function testProductionClaimWithoutVerbRequiresGrounding(): void
    {
        $context = 'Начинали для себя. Обычный стиль без затей.';
        $llmOutput = "Свой крой.\nНачинали для себя.";

        $script = $this->composer(ragContext: $context, llmOutput: $llmOutput)->compose($this->brand(), 7);

        self::assertNotSame('Свой крой.', $script->hookA);
        self::assertNotContains('Свой крой.', $script->bits);
    }

    /**
     * Топ-скор не обязан быть хуком: телеграфный обрывок («Есть цвет.») даже с более высоким
     * скором (короче 16 знаков) не тянет на hookA (нет слова ≥6 букв и нет цифры) — хуком
     * становится следующий по скору, ДОСТАТОЧНО «плотный» факт; обрывок остаётся битом.
     */
    public function testHookARequiresRicherCandidateThanTopScored(): void
    {
        $context = 'Начинали для себя. Есть яркий цвет в коллекции.';
        $llmOutput = "Есть цвет.\nНачинали для себя.";

        $script = $this->composer(ragContext: $context, llmOutput: $llmOutput)->compose($this->brand(), 7);

        self::assertSame('Начинали для себя.', $script->hookA);
        self::assertContains('Есть цвет.', $script->bits);
    }

    /**
     * ОБЯЗАТЕЛЬНЫЙ тест пиксельного бюджета: каждая надпись, которую композер реально выдал в
     * репрезентативном наборе сценариев (H1/F1/F2, все фолбэки битов, развязка с категориями),
     * обязана влезать в плашку (imagettfbbox ≤ 952px на кегле рендера) — иначе одна правка
     * текста молча ломает надписи у ВСЕХ брендов сразу.
     */
    public function testEveryProducedLineFitsPixelBudget(): void
    {
        $budget = new SlideTextBudget(self::fontPath());
        $hookFontSize = 54;
        $askFontSize = 40;

        $scripts = [
            // H1 — ушедший бренд + маркетплейс-пара как фолбэк битов.
            $this->composer(departedYaml: "-\n  departed: \"Zara\"\n  alternatives: [\"our-brand\"]\n")
                ->compose($this->brand(slug: 'our-brand'), 7),
            // F2 — фактов нет вообще (короткая формулировка комиссии, без битов).
            $this->composer()->compose($this->brand(), 4),
            // F2 — детерминированный добор: год / категории / материал.
            $this->composer()->compose($this->brand(foundingYear: '1998'), 7),
            // F2.real — позиционный тезис «фото реальные», детерминированный добор битов.
            $this->composer()->compose($this->brand(slug: 'brand-4', foundingYear: '1998'), 7),
            $this->composer(materials: ['хлопок'])->compose($this->brand(), 7),
            // F1 — grounded RAG-факт ведёт хук.
            $this->composer(
                ragContext: 'Бренд начинали для себя в 2015 году. Первый цех — обычный гараж.',
                llmOutput: "Начинали для себя.\nПервый цех — гараж.",
            )->compose($this->brand(), 9),
            // Развязка — город + 2 известные категории мн.ч.
            $this->composer(categories: ['куртка', 'футболка'])->compose($this->brand(city: 'Уфа'), 4),
        ];

        foreach ($scripts as $script) {
            self::assertTrue($budget->fits($script->hookA, $hookFontSize), 'hookA: ' . $script->hookA);
            self::assertTrue($budget->fits($script->hookB, $hookFontSize), 'hookB: ' . $script->hookB);
            foreach ($script->bits as $bit) {
                self::assertTrue($budget->fits($bit, $hookFontSize), 'бит: ' . $bit);
            }
            self::assertTrue($budget->fits($script->finaleMeta, $askFontSize), 'мета: ' . $script->finaleMeta);
            self::assertTrue($budget->fits($script->finaleAsk, $askFontSize), 'просьба: ' . $script->finaleAsk);
        }
    }

    private function composer(
        ?string $ragContext = null,
        ?string $llmOutput = null,
        array $categories = [],
        array $materials = [],
        ?string $departedYaml = null,
    ): SlideScriptComposer {
        $rag = $this->createMock(BrandRagService::class);
        $rag->method('retrieve')->willReturn([
            'context' => $ragContext,
            'score' => $ragContext !== null ? 0.9 : null,
            'chunks' => $ragContext !== null ? 5 : 0,
        ]);

        $llm = $this->createMock(LlmService::class);
        $llm->method('generate')->willReturn($llmOutput ?? '');

        $attributes = $this->createMock(BrandAttributeRepository::class);
        $attributes->method('findValuesByBrandAndName')->willReturnCallback(
            static function (Brand $brand, string $name) use ($categories, $materials): array {
                return match ($name) {
                    BrandAttribute::NAME_CATEGORY => $categories,
                    BrandAttribute::NAME_MATERIAL => $materials,
                    default => [],
                };
            },
        );

        return new SlideScriptComposer(
            $llm,
            $rag,
            new ContentValidator(),
            $attributes,
            new SlideTextBudget(self::fontPath()),
            $this->yamlPath($departedYaml ?? self::DEPARTED_YAML_EMPTY),
        );
    }

    private function yamlPath(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'departed_') . '.yaml';
        file_put_contents($path, $contents);

        // Валидируем сразу — опечатка в фикстуре теста не должна маскироваться под «нет матча».
        Yaml::parse($contents);

        return $path;
    }

    private function brand(string $title = 'Тест', ?string $city = null, ?string $slug = null, ?string $foundingYear = null): Brand
    {
        $brand = (new Brand())->setTitle($title);
        if ($city !== null) {
            $brand->setCity($city);
        }
        if ($slug !== null) {
            $brand->setSlug($slug);
        }
        if ($foundingYear !== null) {
            $brand->setFoundingYear($foundingYear);
        }

        return $brand;
    }

    private static function fontPath(): string
    {
        return __DIR__ . '/../../../config/social/fonts/NotoSans.ttf';
    }
}
