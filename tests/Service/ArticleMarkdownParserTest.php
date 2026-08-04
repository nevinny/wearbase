<?php

namespace App\Tests\Service;

use App\Service\Seo\ArticleMarkdownParser;
use PHPUnit\Framework\TestCase;

/** Якоря заголовков + якорные ссылки оглавления (листиклы → publish-blog). */
class ArticleMarkdownParserTest extends TestCase
{
    public function testAnchorId(): void
    {
        self::assertSame('1-меч', ArticleMarkdownParser::anchorId('1. МЕЧ'));
        self::assertSame('коротко', ArticleMarkdownParser::anchorId('Коротко'));
        self::assertSame('gate31-обзор', ArticleMarkdownParser::anchorId('Gate31 — обзор!'));
    }

    public function testHeadingIdsAndTocAnchorLinks(): void
    {
        $md = <<<MD
        # ТОП-2 бренда

        ## Содержание

        - [Коротко](#коротко)
        - [1. МЕЧ](#1-меч)

        ## Коротко

        Ответ.

        ## 1. МЕЧ

        - **Город:** Санкт-Петербург

        Текст про бренд.
        MD;

        [, , $html] = (new ArticleMarkdownParser())->parse($md);

        self::assertStringContainsString('<h2 id="коротко">Коротко</h2>', $html);
        self::assertStringContainsString('<h2 id="1-меч">1. МЕЧ</h2>', $html);
        self::assertStringContainsString('<a href="#1-меч">1. МЕЧ</a>', $html);
        self::assertStringContainsString('<li><strong>Город:</strong> Санкт-Петербург</li>', $html);
    }

    /** SEO-title (Т—Ж): комментарий meta-title извлекается и не попадает в HTML. */
    public function testMetaTitleComment(): void
    {
        $md = <<<MD
        <!-- площадка: site · автор-персона: стилист -->
        <!-- meta-title: Топ-5 лучших брендов streetwear 2026 года -->

        # ТОП-5 брендов streetwear: рейтинг

        Текст.
        MD;

        [$title, , $html, $metaTitle] = (new ArticleMarkdownParser())->parse($md);

        self::assertSame('ТОП-5 брендов streetwear: рейтинг', $title);
        self::assertSame('Топ-5 лучших брендов streetwear 2026 года', $metaTitle);
        self::assertStringNotContainsString('meta-title', $html);
    }

    /** Старые .md без meta-title: 4-й элемент null (fallback на title в шаблоне). */
    public function testMetaTitleAbsent(): void
    {
        [, , , $metaTitle] = (new ArticleMarkdownParser())->parse("# Заголовок\n\nТекст.");

        self::assertNull($metaTitle);
    }

    /**
     * Регресс: строка-артефакт «##»/«### » без текста после маркера раньше вешала
     * mdToHtml() в бесконечный цикл (accumulator абзаца и ветка заголовка обе
     * отвергали строку, не продвигая курсор). set_time_limit — страховка: если
     * защита когда-нибудь регрессирует, тест упадёт по таймауту, а не подвесит
     * весь прогон. Лимит действует на весь остаток PHP-процесса (не только на
     * этот тест) — finally возвращает безлимит CLI, иначе GD-тяжёлые тесты
     * дальше по суите падают fatal'ом на медленном CI-раннере.
     */
    public function testStrayHeadingMarkerDoesNotHang(): void
    {
        set_time_limit(5);

        try {
            $md = "# Заголовок\n\n## Коротко\n\nОтвет.\n\nтекст\n##\n\nещё текст\n### \n\n"
                . "## Раздел\n\n- пункт [ссылка](https://example.com)\n";

            $result = (new ArticleMarkdownParser())->parse($md);
        } finally {
            set_time_limit(0);
        }

        self::assertNotNull($result);
        [$title, , $html] = $result;
        self::assertSame('Заголовок', $title);
        self::assertStringContainsString('<h2', $html);
        self::assertStringContainsString('<p>текст ##</p>', $html);
        self::assertStringContainsString('<p>ещё текст</p>', $html);
        self::assertStringContainsString('<p>###</p>', $html);
        self::assertStringContainsString('<li>пункт <a href="https://example.com">ссылка</a></li>', $html);
    }
}
