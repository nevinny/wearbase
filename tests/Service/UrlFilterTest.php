<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\UrlFilter;
use PHPUnit\Framework\TestCase;

class UrlFilterTest extends TestCase
{
    private UrlFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new UrlFilter('');
    }

    /** Самозагрязнение: свои домены не скрейпим никогда. */
    public function testSelfDomainsExcluded(): void
    {
        self::assertTrue($this->filter->isExcluded('https://wearbase.ru/ru/brands/x'));
        self::assertTrue($this->filter->isExcluded('https://www.wearbase.ru/'));
        self::assertTrue($this->filter->isExcluded('https://russianstreetwear.club/brand'));
    }

    /** Job-хосты: упоминают бренд как работодателя — шум (инцидент Zatmenie). */
    public function testJobNoiseExcluded(): void
    {
        self::assertTrue($this->filter->isExcluded('https://hh.ru/vacancy/1'));
        self::assertTrue($this->filter->isExcluded('https://dreamjob.ru/employers/2'));
        self::assertTrue($this->filter->isExcluded('https://trud.com/q'));
    }

    /** Suffix-матчинг ловит поддомены (saratov.jobfilter.ru). */
    public function testSubdomainsCaught(): void
    {
        self::assertTrue($this->filter->isExcluded('https://saratov.jobfilter.ru/company/x'));
        self::assertTrue($this->filter->isExcluded('https://m.hh.ru/vacancy/1'));
    }

    /** Маркетплейсы НЕ исключаются — там реальные описания брендов. */
    public function testMarketplacesAllowed(): void
    {
        self::assertFalse($this->filter->isExcluded('https://www.ozon.ru/brand/x'));
        self::assertFalse($this->filter->isExcluded('https://www.wildberries.ru/brands/y'));
        self::assertFalse($this->filter->isExcluded('https://example-brand.ru/'));
    }

    /** Похожие, но НЕ поддомены — не ловим (wearbase.ru.evil.com — отдельный хост). */
    public function testNoFalsePositiveOnLookalike(): void
    {
        self::assertFalse($this->filter->isExcluded('https://notwearbase.ru/'));
    }

    /** Fail-closed: нечитаемый/пустой host исключается. */
    public function testFailClosed(): void
    {
        self::assertTrue($this->filter->isExcluded('not-a-url'));
        self::assertTrue($this->filter->isExcluded(''));
    }

    /** Доп. исключения через env (comma-separated). */
    public function testExtraExcludedDomains(): void
    {
        $filter = new UrlFilter('spam.example, evil.org');
        self::assertTrue($filter->isExcluded('https://spam.example/page'));
        self::assertTrue($filter->isExcluded('https://sub.evil.org/x'));
        self::assertFalse($filter->isExcluded('https://good.example/page'));
    }
}
