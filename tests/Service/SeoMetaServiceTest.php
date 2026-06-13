<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SeoMetaService;
use PHPUnit\Framework\TestCase;

class SeoMetaServiceTest extends TestCase
{
    private SeoMetaService $s;

    protected function setUp(): void
    {
        $this->s = new SeoMetaService();
    }

    public function testFitKeepsShortTextUnchanged(): void
    {
        self::assertSame('Короткий заголовок', $this->s->fit('Короткий заголовок', 60));
    }

    public function testFitTrimsOnWordBoundary(): void
    {
        $text = 'Российский бренд женской одежды из города Рославль семейное производство платьев';
        $out  = $this->s->fit($text, 40);
        self::assertLessThanOrEqual(40, mb_strlen($out));
        // не обрывает слово посередине — последнее слово целое
        self::assertStringEndsNotWith('-', $out);
        self::assertStringContainsString('Российский бренд женской одежды', $out);
        // обрезанного «горо…» быть не должно
        self::assertDoesNotMatchRegularExpression('/\bгоро$/u', $out);
    }

    public function testFitStripsTrailingPunctuation(): void
    {
        $out = $this->s->fit('Платья, блузы, костюмы, кафтаны, юбки, брюки, пальто и аксессуары для женщин', 30);
        self::assertLessThanOrEqual(30, mb_strlen($out));
        self::assertStringEndsNotWith(',', $out);
    }

    public function testFitStripsDanglingPipeSeparator(): void
    {
        // обрезали «… купить | WEARBASE» — висячий «|» оставаться не должен
        $out = $this->s->fit('Бренд Wahhid — одежда streetwear, отзывы, купить | WEARBASE', 52);
        self::assertLessThanOrEqual(52, mb_strlen($out));
        self::assertStringEndsNotWith('|', rtrim($out));
        self::assertStringEndsNotWith('|', $out);
    }

    public function testFitTitleForRenderReservesSuffixWhenAbsent(): void
    {
        // нет WEARBASE → шаблон добавит « | WEARBASE» (11) → итог должен остаться ≤60
        $title    = 'Бренд Wahhid (Ваххид) — одежда streetwear, отзывы, купить, доставка';
        $stored   = $this->s->fitTitleForRender($title);
        $rendered = str_contains($stored, 'WEARBASE') ? $stored : $stored . ' | WEARBASE';
        self::assertLessThanOrEqual(60, mb_strlen($rendered));
        self::assertStringNotContainsString('WEARBASE', $stored); // суффикс добавит шаблон
    }

    public function testFitTitleForRenderKeepsSuffixedTitleUnderLimit(): void
    {
        $title  = 'NEVERLATE — бренд одежды | WEARBASE';
        $stored = $this->s->fitTitleForRender($title);
        self::assertSame($title, $stored); // ≤60 и уже с суффиксом — не трогаем
    }

    public function testBuildTitleFitsLimitAndKeepsBrand(): void
    {
        $title = $this->s->buildTitle('GATE31', 'Санкт-Петербург');
        self::assertLessThanOrEqual(SeoMetaService::MAX_TITLE, mb_strlen($title));
        self::assertStringContainsString('GATE31', $title);
    }

    public function testBuildTitleLongBrandStillFits(): void
    {
        $title = $this->s->buildTitle('Очень Длинное Название Бренда Одежды Из Нескольких Слов', 'Екатеринбург');
        self::assertLessThanOrEqual(SeoMetaService::MAX_TITLE, mb_strlen($title));
    }

    public function testBuildDescriptionFromSourceTrimmed(): void
    {
        $source = str_repeat('слово ', 80); // заведомо длиннее 155
        $out    = $this->s->buildDescription($source, 'Бренд', 'Москва');
        self::assertLessThanOrEqual(SeoMetaService::MAX_DESCRIPTION, mb_strlen($out));
    }

    public function testBuildDescriptionTemplateWhenNoSource(): void
    {
        $out = $this->s->buildDescription(null, 'Барка', 'Казань');
        self::assertLessThanOrEqual(SeoMetaService::MAX_DESCRIPTION, mb_strlen($out));
        self::assertStringContainsString('Барка', $out);
        self::assertStringContainsString('Казань', $out);
    }
}
