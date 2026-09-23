<?php

declare(strict_types=1);

namespace App\Tests\Service\Seo;

use App\Service\Seo\CompetitorPageClassifier;
use App\Service\UrlFilter;
use PHPUnit\Framework\TestCase;

class CompetitorPageClassifierTest extends TestCase
{
    private CompetitorPageClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new CompetitorPageClassifier(new UrlFilter(''));
    }

    public function testSelfDomainIsOther(): void
    {
        self::assertSame(
            CompetitorPageClassifier::TYPE_OTHER,
            $this->classifier->classify('https://wearbase.ru/ru/brands/x', []),
        );
    }

    public function testJobNoiseIsOther(): void
    {
        self::assertSame(
            CompetitorPageClassifier::TYPE_OTHER,
            $this->classifier->classify('https://hh.ru/vacancy/1', []),
        );
    }

    public function testMarketplacesDetected(): void
    {
        self::assertSame(CompetitorPageClassifier::TYPE_MARKETPLACE, $this->classifier->classify('https://www.wildberries.ru/catalog/123', []));
        self::assertSame(CompetitorPageClassifier::TYPE_MARKETPLACE, $this->classifier->classify('https://www.ozon.ru/product/456', []));
        self::assertSame(CompetitorPageClassifier::TYPE_MARKETPLACE, $this->classifier->classify('https://market.yandex.ru/product/789', []));
    }

    public function testSocialDetected(): void
    {
        self::assertSame(CompetitorPageClassifier::TYPE_SOCIAL, $this->classifier->classify('https://vk.com/brandname', []));
        self::assertSame(CompetitorPageClassifier::TYPE_SOCIAL, $this->classifier->classify('https://www.instagram.com/brandname', []));
        self::assertSame(CompetitorPageClassifier::TYPE_SOCIAL, $this->classifier->classify('https://t.me/s/brandname', []));
    }

    public function testOfficialBrandSiteDetected(): void
    {
        self::assertSame(
            CompetitorPageClassifier::TYPE_OFFICIAL_BRAND,
            $this->classifier->classify('https://www.example-brand.ru/about', ['example-brand.ru']),
        );
    }

    public function testOfficialHostMatchIsExactNotSuffix(): void
    {
        // Официальный сайт матчится ТОЧНО по хосту (после снятия www.), а не по суффиксу —
        // иначе evil-example-brand.ru ложно посчитался бы официальным сайтом бренда.
        self::assertNotSame(
            CompetitorPageClassifier::TYPE_OFFICIAL_BRAND,
            $this->classifier->classify('https://evil-example-brand.ru/about', ['example-brand.ru']),
        );
    }

    public function testMarketplaceWinsOverOfficialHostList(): void
    {
        // Порядок проверки: маркетплейс/соцсеть проверяются РАНЬШЕ official — бренд,
        // который ошибочно указал WB-магазин как «website» в brand_link, не должен
        // классифицироваться как official_brand_site.
        self::assertSame(
            CompetitorPageClassifier::TYPE_MARKETPLACE,
            $this->classifier->classify('https://www.wildberries.ru/brand/123', ['wildberries.ru']),
        );
    }

    public function testUnknownDomainIsArticle(): void
    {
        self::assertSame(
            CompetitorPageClassifier::TYPE_ARTICLE,
            $this->classifier->classify('https://some-fashion-blog.ru/top-brands', []),
        );
    }

    public function testEmptyHostIsOther(): void
    {
        self::assertSame(CompetitorPageClassifier::TYPE_OTHER, $this->classifier->classify('not-a-url', []));
    }
}
