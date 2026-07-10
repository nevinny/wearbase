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
}
