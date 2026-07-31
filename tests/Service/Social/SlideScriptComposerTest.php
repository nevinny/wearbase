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
 * Лестница хуков v3 (H1 ушедший → H2 город → H3 факты → H4 общий), биты-факты (grounded LLM +
 * детерминированный «добор») и development-развязка. Ядро проверок: правильная ступень
 * выигрывает, дедуп/грaунdинг реально выбрасывают невалидные кандидаты, а НИ ОДНА константная
 * строка не переполняет пиксельный бюджет плашки (SlideTextBudget) — иначе она молча ломает ВСЕ
 * посты сразу, а не один конкретный бренд.
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
        self::assertSame('h1.departed|b.det2|c.save', $script->scriptKey);
    }

    /** Имя ушедшего не влезает в плашку → лестница опускается на H2 (город доступен). */
    public function testDepartedNameTooLongFallsBackToCity(): void
    {
        $yaml = <<<'YAML'
-
  departed: "Очень-очень-длинное-название-бренда-которое-никогда-не-влезет"
  alternatives: ["our-brand"]
YAML;

        $script = $this->composer(departedYaml: $yaml)
            ->compose($this->brand(slug: 'our-brand', city: 'Пермь'), totalSlides: 7);

        self::assertSame('Угадай город.', $script->hookA);
        self::assertStringStartsWith('h2.city|', $script->scriptKey);
    }

    public function testCityStageWinsWhenNoDepartedMatch(): void
    {
        $script = $this->composer()->compose($this->brand(city: 'Пермь'), totalSlides: 7);

        self::assertSame('Угадай город.', $script->hookA);
        self::assertSame('Скажу в конце.', $script->hookB);
        self::assertSame('Пермь', $script->finaleMeta);
        self::assertStringStartsWith('h2.city|', $script->scriptKey);
    }

    /** Москва и Санкт-Петербург — угадывать нечего, лестница уходит ниже. */
    public function testMoscowAndSpbDoNotTriggerCityStage(): void
    {
        foreach (['Москва', 'Санкт-Петербург'] as $city) {
            $script = $this->composer()->compose($this->brand(city: $city), totalSlides: 4); // budget=0
            self::assertSame('Имя — в конце.', $script->hookA, $city);
            self::assertSame('Просто посмотри.', $script->hookB, $city);
            self::assertSame('h4.generic|b.none|c.save', $script->scriptKey, $city);
        }
    }

    public function testFactsStageWinsWithGroundedBits(): void
    {
        $context = 'Бренд начинали для себя в 2015 году. Первый цех — обычный гараж. '
            . 'Ткань — только футер. Название — фамилия основателя.';
        $llmOutput = "Начинали для себя.\nПервый цех — гараж.\nНазвание — фамилия.\nОснован в 1990.";

        $script = $this->composer(ragContext: $context, llmOutput: $llmOutput)
            ->compose($this->brand(), totalSlides: 7); // budget=2

        self::assertSame('Имя — в конце.', $script->hookA);
        self::assertSame('Сначала — два факта.', $script->hookB);
        self::assertCount(2, $script->bits);
        // «Основан в 1990.» — год НЕ упомянут в выдержках (там 2015) → выброшен без ретрая.
        self::assertNotContains('Основан в 1990.', $script->bits);
        self::assertStringStartsWith('h3.facts|b.rag2|c.save', $script->scriptKey);
    }

    public function testGenericStageIsUltimateFallback(): void
    {
        $script = $this->composer()->compose($this->brand(), totalSlides: 4); // budget=0, нет города/departed/битов

        self::assertSame('Имя — в конце.', $script->hookA);
        self::assertSame('Просто посмотри.', $script->hookB);
        self::assertSame([], $script->bits);
        self::assertSame('h4.generic|b.none|c.save', $script->scriptKey);
    }

    /**
     * Цифра, которой нет в выдержках (и не год основания) — кандидат выброшен целиком (не
     * доходит до финального списка, даже если пустой бюджет добирается детерминированным
     * фолбэком — здесь маркетплейс-парой, потому что у бренда нет ни года, ни категорий).
     */
    public function testUngroundedNumberRejectsCandidate(): void
    {
        $context = 'Бренд шьёт футболки без лишних цифр в описании.';
        $llmOutput = 'Продано 5000 штук.';

        $script = $this->composer(ragContext: $context, llmOutput: $llmOutput)
            ->compose($this->brand(), totalSlides: 7);

        self::assertNotContains('Продано 5000 штук.', $script->bits);
    }

    /** Строка из одних общих слов каталога — не факт, отбраковывается как филлер. */
    public function testFillerLineIsRejected(): void
    {
        $context = 'Компания продаёт одежду с 2015 года. Первый цех — гараж.';
        $llmOutput = "Это бренд одежды.\nПервый цех — гараж.";

        $script = $this->composer(ragContext: $context, llmOutput: $llmOutput)
            ->compose($this->brand(), totalSlides: 7);

        self::assertNotContains('Это бренд одежды.', $script->bits);
        self::assertContains('Первый цех — гараж.', $script->bits);
    }

    /** Два похожих факта (один и тот же корневой смысл) — второй дедупится. */
    public function testDuplicateBitsAreDeduped(): void
    {
        $context = 'Ткань — только шерсть. Ткань очень мягкая и приятная на ощупь. Первый цех — гараж.';
        $llmOutput = "Ткань — только шерсть.\nТкань очень мягкая.\nПервый цех — гараж.";

        $script = $this->composer(ragContext: $context, llmOutput: $llmOutput)
            ->compose($this->brand(), totalSlides: 9); // budget=3, но дублей быть не должно

        $tkanLines = array_filter($script->bits, static fn (string $b) => str_starts_with($b, 'Ткань'));
        self::assertCount(1, $tkanLines, 'Обе строки про ткань делят один дедуп-ключ — выжить должна одна');
    }

    /** Бит, чей дедуп-ключ совпал с уже выбранным хуком, выбрасывается — идёт следующий фолбэк. */
    public function testBitCollidingWithHookKeyIsDropped(): void
    {
        $script = $this->composer(categories: ['угадайка', 'футболки'], materials: ['хлопок'])
            ->compose($this->brand(city: 'Пермь'), totalSlides: 7); // H2: hookA='Угадай город.' → ключ 'угада'

        self::assertSame('Угадай город.', $script->hookA);
        // «Угадайка, футболки.» делит 5-префикс с «Угадай» → отброшен целиком, несмотря на валидность.
        self::assertNotContains('Угадайка, футболки.', $script->bits);
        self::assertContains('Ткань — хлопок.', $script->bits);
    }

    public function testFoundingYearBitUsedAsDeterministicFallback(): void
    {
        $script = $this->composer()->compose($this->brand(foundingYear: '1998'), totalSlides: 7); // budget=2

        // Единственный фолбэк-бит: остаётся 1 свободный слот — пары маркетплейса не бывает.
        self::assertSame(['Основан в 1998.'], $script->bits);
        // ≥1 бита (пусть и детерминированного) уже переводит лестницу на H3, а не только RAG-биты.
        self::assertSame('h3.facts|b.det1|c.save', $script->scriptKey);
        self::assertSame('Сначала — один факт.', $script->hookB);
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

    public function testMarketplacePairAddedOnlyWhenTwoSlotsRemain(): void
    {
        $withTwoSlots = $this->composer()->compose($this->brand(), totalSlides: 7); // budget=2
        self::assertSame(['Маркетплейс — 30–67%.', 'Мы — 0%.'], $withTwoSlots->bits);

        $withOneSlot = $this->composer()->compose($this->brand(), totalSlides: 5); // budget=1
        self::assertSame([], $withOneSlot->bits, 'Пара маркетплейса не бывает половинчатой');
    }

    public function testFinaleMetaCombinesCityAndCategories(): void
    {
        $script = $this->composer(categories: ['брюки', 'футболки', 'платья'])
            ->compose($this->brand(city: 'Пермь'), totalSlides: 4);

        self::assertSame('Пермь · брюки, футболки', $script->finaleMeta);
        self::assertLessThanOrEqual(SlideScript::FINALE_META_MAX_CHARS, mb_strlen($script->finaleMeta));
    }

    public function testFinaleMetaFallsBackToCategoriesWithoutCity(): void
    {
        $script = $this->composer(categories: ['брюки', 'футболки'])->compose($this->brand(), totalSlides: 4);

        self::assertSame('Брюки, футболки', $script->finaleMeta);
    }

    public function testFinaleMetaUltimateFallbackWithoutAnyData(): void
    {
        $script = $this->composer()->compose($this->brand(), totalSlides: 4);

        self::assertSame('Российский бренд', $script->finaleMeta);
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
     * Повторный вызов на тех же данных обязан давать РОВНО тот же сценарий: в лестнице v3 нет
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

    /**
     * ОБЯЗАТЕЛЬНЫЙ тест пиксельного бюджета: каждая надпись, которую композер реально выдал в
     * репрезентативном наборе сценариев (все ступени лестницы, все фолбэки битов), обязана
     * влезать в плашку (imagettfbbox ≤ 952px на кегле рендера) — иначе одна правка текста молча
     * ломает надписи у ВСЕХ брендов сразу.
     */
    public function testEveryProducedLineFitsPixelBudget(): void
    {
        $budget = new SlideTextBudget(self::fontPath());
        $hookFontSize = 54;
        $askFontSize = 40;

        $scripts = [
            $this->composer(departedYaml: "-\n  departed: \"Zara\"\n  alternatives: [\"our-brand\"]\n")
                ->compose($this->brand(slug: 'our-brand'), 7),
            $this->composer()->compose($this->brand(city: 'Пермь'), 7),
            $this->composer()->compose($this->brand(city: 'Москва'), 7),
            $this->composer(
                ragContext: 'Бренд начинали для себя в 2015 году. Первый цех — гараж. Ткань — футер.',
                llmOutput: "Начинали для себя.\nПервый цех — гараж.\nТкань — только футер.",
            )->compose($this->brand(), 9),
            $this->composer()->compose($this->brand(), 4),
            $this->composer(categories: ['брюки', 'футболки', 'платья'])->compose($this->brand(), 7),
            $this->composer(materials: ['хлопок'])->compose($this->brand(), 7),
            $this->composer()->compose($this->brand(foundingYear: '1998'), 7),
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
