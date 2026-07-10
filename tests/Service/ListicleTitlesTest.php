<?php

namespace App\Tests\Service;

use App\Command\GenerateListicleCommand;
use PHPUnit\Framework\TestCase;

/** Приём Т—Ж: SEO-title с годом при человеческом H1 без года (вечнозелёный URL). */
class ListicleTitlesTest extends TestCase
{
    public function testSeoTitleHasYear(): void
    {
        self::assertSame(
            'Топ-5 лучших брендов streetwear 2026 года',
            GenerateListicleCommand::seoTitle(5, 'streetwear', 2026),
        );
        // Год по умолчанию — из даты генерации.
        self::assertStringContainsString(date('Y') . ' года', GenerateListicleCommand::seoTitle(5, 'streetwear'));
    }

    public function testH1HasNoYear(): void
    {
        $h1 = GenerateListicleCommand::h1Title(5, 'streetwear');

        self::assertSame('ТОП-5 брендов streetwear: рейтинг', $h1);
        self::assertDoesNotMatchRegularExpression('/\b20\d{2}\b/', $h1);
    }
}
