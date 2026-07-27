<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Brand;
use App\Entity\BrandKeyword;
use App\Repository\BrandKeywordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * findByBrandRanked()/findTopByBrand() питают генерацию контента и соц-посты —
 * фразы с niche_status='off' («яндекс погода» и т.п.) не должны туда попадать.
 * NULL (не проверена) — fail-open, должна остаться.
 */
class BrandKeywordNicheGateTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BrandKeywordRepository $keywords;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->keywords = $c->get(BrandKeywordRepository::class);

        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    private function keyword(Brand $brand, string $phrase, ?string $nicheStatus, int $shows = 100): BrandKeyword
    {
        $k = (new BrandKeyword())
            ->setBrand($brand)
            ->setKeyword($phrase)
            ->setMonthlyShows($shows)
            ->setNicheStatus($nicheStatus);
        $this->em->persist($k);

        return $k;
    }

    public function testFindByBrandRankedExcludesOffNiche(): void
    {
        $brand = (new Brand())->setTitle('Гараж')->setSlug('garazh-niche-test');
        $this->em->persist($brand);

        $this->keyword($brand, 'купить платье', null, 500);
        $this->keyword($brand, 'джинсы мужские', BrandKeyword::NICHE_IN, 400);
        $this->keyword($brand, 'яндекс погода', BrandKeyword::NICHE_OFF, 300);
        $this->em->flush();

        $result = $this->keywords->findByBrandRanked($brand, 10);
        $phrases = array_map(static fn (BrandKeyword $k) => $k->getKeyword(), $result);

        self::assertContains('купить платье', $phrases, 'NULL (не проверена) должна пройти fail-open');
        self::assertContains('джинсы мужские', $phrases, 'in должна пройти');
        self::assertNotContains('яндекс погода', $phrases, 'off должна быть отфильтрована');
    }

    /**
     * Регресс на скобки в andWhere: без обёртки `(A OR B)` предикат ниши
     * сливался с `k.brand = :brand` через AND-приоритет и подтягивал 'in'-фразы
     * ЧУЖИХ брендов. Проверяем, что фильтр не размывает ограничение по бренду.
     */
    public function testFindByBrandRankedDoesNotLeakOtherBrandsKeywords(): void
    {
        $a = (new Brand())->setTitle('Бренд А')->setSlug('brand-a-niche-leak');
        $b = (new Brand())->setTitle('Бренд Б')->setSlug('brand-b-niche-leak');
        $this->em->persist($a);
        $this->em->persist($b);

        $this->keyword($a, 'платье бренда а', null, 100);
        $this->keyword($b, 'чужой ключ ин', BrandKeyword::NICHE_IN, 9000);
        $this->keyword($b, 'чужой ключ null', null, 8000);
        $this->em->flush();

        $phrases = array_map(
            static fn (BrandKeyword $k) => $k->getKeyword(),
            $this->keywords->findByBrandRanked($a, 10),
        );

        self::assertSame(['платье бренда а'], $phrases, 'должны вернуться только ключи бренда A');
    }

    public function testFindTopByBrandSkipsOffNicheEvenWithHighestShows(): void
    {
        $brand = (new Brand())->setTitle('Гараж 2')->setSlug('garazh-niche-test-2');
        $this->em->persist($brand);

        $this->keyword($brand, 'яндекс игры', BrandKeyword::NICHE_OFF, 9000);
        $this->keyword($brand, 'куртка оверсайз', null, 500);
        $this->em->flush();

        $top = $this->keywords->findTopByBrand($brand);

        self::assertNotNull($top);
        self::assertSame('куртка оверсайз', $top->getKeyword());
    }
}
