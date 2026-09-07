<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Brand;
use App\Entity\BrandAudience;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;

/**
 * Рендер фасетной страницы аудитории /{_locale}/audience/{slug} (по образцу
 * CityPageRenderTest) — H1 в человеко-читаемой формулировке («Российские бренды
 * женской одежды», а не «Бренды одежды для аудитории Женщины»), карточки <h3>,
 * 404 у аудитории без опубликованных брендов.
 */
final class AudiencePageRenderTest extends DatabaseDependentWebTestCase
{
    private array $brandIds = [];
    private array $audienceIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        foreach ($this->brandIds as $id) {
            $brand = $em->find(Brand::class, $id);
            if ($brand !== null) {
                $em->remove($brand);
            }
        }
        $em->flush();
        foreach ($this->audienceIds as $id) {
            $audience = $em->find(BrandAudience::class, $id);
            if ($audience !== null) {
                $em->remove($audience);
            }
        }
        $em->flush();
        $this->brandIds = [];
        $this->audienceIds = [];
        parent::tearDown();
    }

    public function testPageRendersH1AndH3Cards(): void
    {
        $audience = $this->makeAudience('audience-render-women', 'Российские бренды женской одежды');
        $this->makeBrand('audience-render-brand-one', $audience, 'Первый бренд');
        $this->makeBrand('audience-render-brand-two', $audience, 'Второй бренд');
        static::ensureKernelShutdown();

        $client = static::createClient();
        $client->request('GET', '/ru/audience/' . $audience->getSlug());

        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('Российские бренды женской одежды', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, '<h3'));
    }

    public function testEmptyAudienceReturns404(): void
    {
        $audience = $this->makeAudience('audience-render-empty', 'Российские бренды пустой категории');
        static::ensureKernelShutdown();

        $client = static::createClient();
        $client->request('GET', '/ru/audience/' . $audience->getSlug());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnknownSlugReturns404(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/ru/audience/no-such-audience-slug');

        $this->assertResponseStatusCodeSame(404);
    }

    private function makeAudience(string $slug, string $h1): BrandAudience
    {
        $em = $this->em();
        $audience = (new BrandAudience())
            ->setSlug($slug)
            ->setTitle($slug)
            ->setH1($h1)
            ->setStatus(Statuses::Active);
        $em->persist($audience);
        $em->flush();
        $this->audienceIds[] = $audience->getId();

        return $audience;
    }

    private function makeBrand(string $slug, BrandAudience $audience, string $title): Brand
    {
        $em = $this->em();
        $brand = (new Brand())
            ->setTitle($title)
            ->setSlug($slug)
            ->setStatus(Statuses::Active);
        $brand->addAudience($audience);
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
