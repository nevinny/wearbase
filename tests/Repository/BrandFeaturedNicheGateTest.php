<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Brand;
use App\Repository\BrandRepository;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * findFeaturedBrands() питает пул соц-постов (SocialPlanner) — офф-ниша (niche_status='off')
 * не должна туда попадать, иначе бренды типа Benlee (запчасти прицепов) лезут в посты.
 */
class BrandFeaturedNicheGateTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BrandRepository $brands;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->brands = $c->get(BrandRepository::class);

        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    private function brand(string $slug, ?string $nicheStatus): Brand
    {
        $b = (new Brand())->setTitle(strtoupper($slug))->setSlug($slug);
        $b->setStatus(Statuses::Active);
        if ($nicheStatus !== null) {
            $b->markNiche($nicheStatus, 'test', new \DateTimeImmutable());
        }
        $this->em->persist($b);
        $this->em->flush();

        return $b;
    }

    public function testFindFeaturedBrandsExcludesOffNiche(): void
    {
        $ok = $this->brand('featured-ok', null);
        $off = $this->brand('featured-off', 'off');

        $results = $this->brands->findFeaturedBrands(12);
        $ids = array_map(static fn (Brand $x) => $x->getId(), $results);

        $this->assertContains($ok->getId(), $ids, 'бренд без вердикта (NULL) должен остаться в пуле');
        $this->assertNotContains($off->getId(), $ids, 'бренд с niche_status=off должен быть отфильтрован');
    }
}
