<?php

declare(strict_types=1);

namespace App\Tests\Social\Castbase;

use App\Social\IgCaption;
use PHPUnit\Framework\TestCase;

class IgCaptionTest extends TestCase
{
    public function testCtaLineGoesBeforeHashtagParagraph(): void
    {
        $caption = "Хук\n\nТекст поста\n\n#один #два";

        self::assertSame(
            "Хук\n\nТекст поста\n\nСмотри бренд — ссылка в профиле\n\n#один #два",
            IgCaption::withCtaLine($caption, 'Смотри бренд'),
        );
    }

    public function testCtaLineAppendedWhenNoHashtags(): void
    {
        self::assertSame("Текст\n\nГо — ссылка в профиле", IgCaption::withCtaLine('Текст', 'Го'));
    }

    public function testEmptyLabelLeavesCaptionUntouched(): void
    {
        self::assertSame("Текст\n\n#т", IgCaption::withCtaLine("Текст\n\n#т", ' '));
        self::assertSame('Текст', IgCaption::withCtaLine('Текст', null));
    }
}
