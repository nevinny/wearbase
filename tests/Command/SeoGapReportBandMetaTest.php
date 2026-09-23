<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeoGapReportCommand;
use App\Service\Seo\SeoQueryGapProvider;
use PHPUnit\Framework\TestCase;

/**
 * SeoGapReportCommand::BAND_META (UI-метаданные: label/icon/action_prefix) и
 * SeoQueryGapProvider::BAND_BOUNDS (границы позиций) — две отдельные константы с
 * рефакторинга 2026-09-23 (провайдер переиспользует app:seo:competitor-scan). Ключи
 * ОБЯЗАНЫ совпадать — иначе execute() падает на неопределённом индексе вместо
 * понятной ошибки (см. guard в SeoGapReportCommand::execute()).
 */
final class SeoGapReportBandMetaTest extends TestCase
{
    public function testBandKeysMatch(): void
    {
        $bandMeta = (new \ReflectionClassConstant(SeoGapReportCommand::class, 'BAND_META'))->getValue();
        $bandBounds = (new \ReflectionClassConstant(SeoQueryGapProvider::class, 'BAND_BOUNDS'))->getValue();

        self::assertSame(array_keys($bandBounds), array_keys($bandMeta));
    }
}
