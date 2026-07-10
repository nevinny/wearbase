<?php

namespace App\Tests\Service;

use App\Entity\Brand;
use App\Entity\BrandAttribute;
use App\Entity\BrandStore;
use App\Entity\Product;
use App\Repository\BrandAttributeRepository;
use App\Service\Seo\BrandFactSheet;
use PHPUnit\Framework\TestCase;

/**
 * Фикс-поля карточки листикла (Кратко/Хиты/Цены/Город/Офлайн): только реальные
 * данные из БД, пустые поля не выводятся.
 */
class BrandFactSheetTest extends TestCase
{
    private function sheet(array $attributes = []): BrandFactSheet
    {
        $repo = $this->createMock(BrandAttributeRepository::class);
        $repo->method('findByBrand')->willReturn($attributes);

        return new BrandFactSheet($repo);
    }

    public function testFullCard(): void
    {
        $brand = (new Brand())
            ->setTitle('МЕЧ')
            ->setAnons("Петербургский streetwear:\nверхняя одежда и базовые вещи")
            ->setCity('Санкт-Петербург');

        $brand->addProduct((new Product())->setTitle('Парка «Север»')->setPrice(12900.0));
        $brand->addProduct((new Product())->setTitle('Худи Base'));

        $brand->addStore((new BrandStore())->setCity('Санкт-Петербург')->setAddress('Лиговский пр., 74'));
        $brand->addStore((new BrandStore())->setCity('Москва')->setAddress('ул. Мясницкая, 15'));

        $md = $this->sheet()->build($brand);

        self::assertSame(
            "- **Кратко:** Петербургский streetwear: верхняя одежда и базовые вещи\n"
            . "- **Хиты:** Парка «Север», Худи Base\n"
            . "- **Цены:** от 12 900 ₽\n"
            . "- **Город:** Санкт-Петербург\n"
            . '- **Офлайн:** 2 офлайн-точки (Санкт-Петербург, Москва)',
            $md,
        );
    }

    public function testPriceSegmentFallbackAndSingleStore(): void
    {
        $brand = (new Brand())->setTitle('Gate31')->setCity('Санкт-Петербург');
        $brand->addStore((new BrandStore())->setCity('Санкт-Петербург')->setAddress('Большая Конюшенная, 2'));

        $attr = (new BrandAttribute())->setName(BrandAttribute::NAME_PRICE_SEGMENT)->setValue('средний');
        $md   = $this->sheet([$attr])->build($brand);

        self::assertStringContainsString('- **Цены:** сегмент — средний', $md);
        self::assertStringContainsString('- **Офлайн:** Санкт-Петербург, Большая Конюшенная, 2', $md);
        self::assertStringNotContainsString('Кратко', $md);
        self::assertStringNotContainsString('Хиты', $md);
    }

    public function testEmptyBrandGivesEmptySheet(): void
    {
        self::assertSame('', $this->sheet()->build((new Brand())->setTitle('X')));
    }

    public function testLongAnonsCutAtSentenceAndCityNotDuplicated(): void
    {
        $first = 'Первое предложение про бренд длиной побольше, чтобы было что показать.';
        $brand = (new Brand())
            ->setTitle('Y')
            ->setAnons($first . ' Второе предложение ' . str_repeat('очень длинное про преимущества, ', 10) . 'конец.');
        $brand->addStore((new BrandStore())->setCity('Москва')->setAddress('Москва, улица Милашенкова, 4А'));

        $md = $this->sheet()->build($brand);

        self::assertStringContainsString('- **Кратко:** ' . $first . "\n", $md);
        self::assertStringContainsString('- **Офлайн:** Москва, улица Милашенкова, 4А', $md);
        self::assertStringNotContainsString('Москва, Москва', $md);
    }
}
