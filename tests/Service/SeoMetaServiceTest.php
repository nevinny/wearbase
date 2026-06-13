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
