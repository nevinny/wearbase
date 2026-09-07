<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Brand;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;

/**
 * Рендер гео-страницы /{_locale}/cities/{slug}.
 *
 * Три вещи, которые чинил PR: карточка бренда без anons оставалась вообще без текста
 * (на СПб таких 45 из 98, при том что описание длиннее 200 символов есть у 83);
 * карточки были размечены <h2>, из-за чего смысловой заголовок терялся среди сотни
 * одинаковых; согласование числительного умело только «1 / много» и давало
 * «опубликовано 94 брендов» в предложении, которое мы отдаём в сниппет.
 */
final class CityPageRenderTest extends DatabaseDependentWebTestCase
{
    private const CITY = 'Тестоград';
    private const SLUG = 'testograd';

    private array $brandIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    protected function tearDown(): void
    {
        if ($this->brandIds !== []) {
            $em = static::getContainer()->get('doctrine.orm.entity_manager');
            foreach ($this->brandIds as $id) {
                $brand = $em->find(Brand::class, $id);
                if ($brand !== null) {
                    $em->remove($brand);
                }
            }
            $em->flush();
            $this->brandIds = [];
        }
        parent::tearDown();
    }

    public function testCardFallsBackToDescriptionWhenAnonsIsEmpty(): void
    {
        $this->makeBrand('city-render-with-anons', 'Анонс этого бренда виден на карточке', null);
        $this->makeBrand('city-render-no-anons', null, 'Описание бренда без анонса — раньше карточка была пустой');
        static::ensureKernelShutdown();

        $client = static::createClient();
        $client->request('GET', '/ru/cities/' . self::SLUG);

        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Анонс этого бренда виден на карточке', $html);
        $this->assertStringContainsString('Описание бренда без анонса', $html);
    }

    public function testBrandCardsUseH3SoTheSectionHeadingStaysUnique(): void
    {
        $this->makeBrand('city-render-h3-one', 'Первый', null);
        $this->makeBrand('city-render-h3-two', 'Второй', null);
        static::ensureKernelShutdown();

        $client = static::createClient();
        $client->request('GET', '/ru/cities/' . self::SLUG);

        $html = (string) $client->getResponse()->getContent();
        // Карточки — h3; h2 остаётся у смысловых секций, а не у каждого бренда.
        $this->assertGreaterThanOrEqual(2, substr_count($html, '<h3'));
        $this->assertLessThanOrEqual(3, substr_count($html, '<h2'));
    }

    public function testNumeralAgreementForTwoToFourBrands(): void
    {
        foreach (['num-one', 'num-two', 'num-three'] as $slug) {
            $this->makeBrand('city-render-' . $slug, 'Анонс', null);
        }
        static::ensureKernelShutdown();

        $client = static::createClient();
        $client->request('GET', '/ru/cities/' . self::SLUG);

        $html = (string) $client->getResponse()->getContent();
        // 3 бренда → «бренда», а не «брендов» (старый шаблон умел только 1/много).
        $this->assertStringContainsString('опубликовано 3 бренда одежды', $html);
        $this->assertStringNotContainsString('опубликовано 3 брендов', $html);
    }

    private function makeBrand(string $slug, ?string $anons, ?string $description): Brand
    {
        $em = $this->em();
        $brand = (new Brand())
            ->setTitle('Бренд ' . $slug)
            ->setSlug($slug)
            ->setCity(self::CITY)
            ->setStatus(Statuses::Active);
        if ($anons !== null) {
            $brand->setAnons($anons);
        }
        if ($description !== null) {
            $brand->setDescription($description);
        }
        $em->persist($brand);
        $em->flush();
        $this->brandIds[] = $brand->getId();

        return $brand;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }
}
