<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Brand;
use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Repository\SocialPostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * findLatestScriptForBrand — единственная защита от повторного вызова LLM за фактами для
 * карусели и Reels одного бренда (SocialGenerateCommand). Гоняем через реальный Doctrine-запрос
 * (не мок), потому что забытый ->setParameter() не ловится юнит-тестами композера/команды.
 */
class SocialPostRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SocialPostRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(SocialPostRepository::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testReturnsLatestPostWithScriptForBrand(): void
    {
        $channel = (new SocialChannel())->setPlatform(SocialChannel::PLATFORM_IG);
        $this->em->persist($channel);

        $brand = (new Brand())->setTitle('Тест')->setSlug('test-repo-brand');
        $this->em->persist($brand);

        $withoutScript = (new SocialPost())->setChannel($channel)->setBrand($brand)->setRubric('brand_gallery');
        $this->em->persist($withoutScript);

        $older = (new SocialPost())->setChannel($channel)->setBrand($brand)->setRubric('brand_gallery')
            ->setScriptKey('h2.city|b.det1|c.save')->setScriptJson('{"hookA":"old"}');
        $this->em->persist($older);

        $newer = (new SocialPost())->setChannel($channel)->setBrand($brand)->setRubric('brand_reels')
            ->setScriptKey('h2.city|b.det1|c.save')->setScriptJson('{"hookA":"new"}');
        $this->em->persist($newer);

        $this->em->flush();

        $found = $this->repo->findLatestScriptForBrand($brand);

        self::assertNotNull($found);
        self::assertSame($newer->getId(), $found->getId());
    }

    public function testReturnsNullWhenBrandHasNoScriptYet(): void
    {
        $channel = (new SocialChannel())->setPlatform(SocialChannel::PLATFORM_IG);
        $this->em->persist($channel);

        $brand = (new Brand())->setTitle('Тест 2')->setSlug('test-repo-brand-2');
        $this->em->persist($brand);

        $post = (new SocialPost())->setChannel($channel)->setBrand($brand)->setRubric('brand_gallery');
        $this->em->persist($post);
        $this->em->flush();

        self::assertNull($this->repo->findLatestScriptForBrand($brand));
    }
}
