<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MarketplaceExitContext;
use PHPUnit\Framework\TestCase;

/**
 * Юнит на E-E-A-T-фильтре: factBlock() отдаёт ТОЛЬКО type=fact; ни одна opinion-claim
 * не должна протечь в вывод (иначе непроверенное «мнение» пойдёт в промпт LLM как факт).
 */
class MarketplaceExitContextTest extends TestCase
{
    private function context(): MarketplaceExitContext
    {
        return new MarketplaceExitContext(\dirname(__DIR__, 2));
    }

    public function testFactBlockExcludesOpinions(): void
    {
        $block = $this->context()->factBlock();

        // Известная opinion-строка из yaml (metric «+8% комиссии → +20–30% к цене») —
        // её быть НЕ должно.
        self::assertStringNotContainsString('20–30% к цене', $block);
        self::assertStringNotContainsString('оценка продавца', $block);
        self::assertStringNotContainsString('по словам продавца', $block);
    }

    public function testFactBlockContainsKnownFact(): void
    {
        $block = $this->context()->factBlock();

        // Известная fact-строка (регулирование 289-ФЗ) — должна присутствовать вместе
        // с источником.
        self::assertStringContainsString('289-ФЗ', $block);
        self::assertStringContainsString('45 дней', $block);
        self::assertStringContainsString('Источник: https://', $block);
    }

    public function testFactsForAngleFiltersByRef(): void
    {
        $ctx = $this->context();

        $dbs = $ctx->factsForAngle(['DBS']);
        self::assertStringContainsString('DBS', $dbs);
        self::assertStringNotContainsString('289-ФЗ', $dbs);

        // Пустой $refs → все факты (эквивалент factBlock).
        self::assertSame($ctx->factBlock(), $ctx->factsForAngle([]));
    }
}
