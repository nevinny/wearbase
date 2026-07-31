<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Brand;
use App\Service\ContentValidator;
use App\Service\Social\SlideScript;
use App\Service\Social\SlideScriptComposer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Надписи на слайдах — единственный текст, который зритель Reels вообще читает, и правится он
 * дороже всего (файлы слайдов кешируются). Поэтому под тестом три вещи, которые ломаются молча:
 * грамматика (падеж города и согласование числительного), детерминизм ротации (иначе ветки A/B
 * получат разный текст и эксперимент грязный) и запрет AI-штампов.
 */
class SlideScriptComposerTest extends TestCase
{
    /** Реальные значения brand.city из корпуса, включая самое длинное и дефисные. */
    private const CITIES = [
        'Москва',
        'Санкт-Петербург',
        'Нижний Новгород',
        'Ростов-на-Дону',
        'Кирово-Чепецк',
        'Йошкар-Ола',
    ];

    /**
     * Тот же seed → тот же текст (повторный рендер поста и вторая ветка A/B обязаны получить
     * одинаковую копию), соседние seed'ы → разные хуки (иначе лента звучит одинаково).
     */
    public function testRotationIsDeterministicAndVariesBetweenNeighbours(): void
    {
        $composer = new SlideScriptComposer();
        $brand = $this->brand('Казань');

        self::assertSame(
            $composer->compose($brand, 42)->hook,
            $composer->compose($brand, 42)->hook,
        );

        $hooks = array_map(
            static fn (int $seed): string => (new SlideScriptComposer())->compose($brand, $seed)->hook,
            [10, 11, 12],
        );
        self::assertCount(3, array_unique($hooks), 'Три соседних бренда получили один и тот же хук');

        // Отрицательный id невозможен, но abs() в ротации — единственное, что отделяет от
        // деления по модулю с отрицательным результатом и падения по индексу.
        self::assertNotSame('', $composer->compose($brand, -3)->hook);
    }

    public function testNoBannedAiPhrases(): void
    {
        $phrases = (new ContentValidator())->getAiPhrases();
        $composer = new SlideScriptComposer();

        foreach ($this->everyText($composer) as $text) {
            foreach ($phrases as $phrase) {
                self::assertFalse(
                    mb_stripos($text, $phrase) !== false,
                    sprintf('AI-фраза «%s» в надписи: %s', $phrase, $text),
                );
            }
        }
    }

    /**
     * Строки обязаны влезать в плашку. Если якорь переполнит строку, автоперенос выкинет вторую
     * строку за предел двух — и от хука останется ровно тот ярлык, от которого мы уходили.
     */
    public function testEveryLineFitsTheBand(): void
    {
        $composer = new SlideScriptComposer();

        foreach (self::CITIES as $city) {
            foreach ([2, 9, 10] as $count) {
                foreach (range(0, 5) as $seed) {
                    $script = $composer->compose($this->brand($city), $seed);

                    $hook = explode("\n", $script->hook);
                    self::assertCount(2, $hook, 'Хук — ровно две строки: якорь + напряжение');
                    $this->assertLinesFit($hook, SlideScript::HOOK_MAX_CHARS);
                    $this->assertLinesFit(explode("\n", $script->retention), SlideScript::HOOK_MAX_CHARS);

                    self::assertLessThanOrEqual(SlideScript::HOOK_MAX_CHARS, mb_strlen($script->ctaLines[0]));
                    $this->assertLinesFit(array_slice($script->ctaLines, 1), SlideScript::CTA_MAX_CHARS);
                }
            }
        }
    }

    /** Город длиннее строки плашки → жертвуем якорем, но не строкой с напряжением. */
    public function testOverlongCityDegradesButKeepsTension(): void
    {
        $composer = new SlideScriptComposer();

        foreach (range(0, 2) as $seed) {
            $script = $composer->compose($this->brand('Комсомольск-на-Амуре-и-Дальше'), $seed);
            $lines = explode("\n", $script->hook);

            self::assertCount(2, $lines);
            $this->assertLinesFit($lines, SlideScript::HOOK_MAX_CHARS);
            self::assertNotSame('', trim($lines[1]));
        }
    }

    /** У 3 брендов из 292 города нет — сценарий обязан собраться и без него. */
    public function testBrandWithoutCityStillGetsFullScript(): void
    {
        $composer = new SlideScriptComposer();

        foreach (range(0, 5) as $seed) {
            $script = $composer->compose(new Brand(), $seed);
            $lines = explode("\n", $script->hook);

            self::assertCount(2, $lines);
            $this->assertLinesFit($lines, SlideScript::HOOK_MAX_CHARS);
        }
    }

    /** CTA обязан просить и вовлечение (комментарий/пересылку), и сохранение — все три сигнала. */
    public function testCtaAsksForCommentSaveAndShare(): void
    {
        $script = (new SlideScriptComposer())->compose($this->brand('Тверь'), 1);

        self::assertGreaterThanOrEqual(3, count($script->ctaLines));
        $rest = implode(' ', array_slice($script->ctaLines, 1));
        self::assertStringContainsString('Сохрани', $rest);
        self::assertStringContainsString('Отправь', $rest);
    }

    /** @param list<string> $lines */
    private function assertLinesFit(array $lines, int $maxChars): void
    {
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(
                $maxChars,
                mb_strlen($line),
                sprintf('Строка длиннее %d знаков: %s', $maxChars, $line),
            );
        }
    }

    /** @return list<string> все надписи всех сценариев на всех сочетаниях данных */
    private function everyText(SlideScriptComposer $composer): array
    {
        $texts = [];
        foreach ([...self::CITIES, ''] as $city) {
            foreach ([2, 9, 10] as $count) {
                foreach (range(0, 5) as $seed) {
                    $script = $composer->compose($this->brand($city), $seed);
                    $texts[] = $script->hook;
                    $texts[] = $script->retention;
                    foreach ($script->ctaLines as $line) {
                        $texts[] = $line;
                    }
                }
            }
        }

        return $texts;
    }

    private function brand(string $city): Brand
    {
        $brand = (new Brand())->setTitle('Тест');

        return $city === '' ? $brand : $brand->setCity($city);
    }
}
