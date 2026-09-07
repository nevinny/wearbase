<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Brand;
use App\Entity\BrandAudience;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:brand:audience-backfill — регулярки по description/anons, без LLM.
 * Ключевые инварианты: «мужские и женские вещи» даёт ДВЕ аудитории; «женственный
 * силуэт» — ни одной (женск ≠ женственн, это про стиль, а не про аудиторию);
 * повторный прогон не плодит дубли в join-таблице.
 */
class BackfillBrandAudienceCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();

        foreach (['Женщины', 'Мужчины', 'Дети', 'Унисекс'] as $title) {
            $slug = match ($title) {
                'Женщины' => 'female',
                'Мужчины' => 'male',
                'Дети'    => 'kids',
                'Унисекс' => 'unisex',
            };
            $this->em->persist((new BrandAudience())->setTitle($title)->setSlug($slug));
        }
        $this->em->flush();

        $this->tester = new CommandTester((new Application(self::$kernel))->find('app:brand:audience-backfill'));
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    private function brand(string $slug, string $description): Brand
    {
        $brand = (new Brand())->setTitle($slug)->setSlug($slug)->setDescription($description);
        $this->em->persist($brand);
        $this->em->flush();

        return $brand;
    }

    public function testBothAudiencesTaggedForMixedText(): void
    {
        $brand = $this->brand('mixed-brand', 'Бренд выпускает мужские и женские вещи в спортивном стиле.');

        $this->tester->execute(['--id' => (string) $brand->getId()]);

        $this->tester->assertCommandIsSuccessful();
        $this->em->refresh($brand);
        $titles = array_map(static fn (BrandAudience $a) => $a->getTitle(), $brand->getAudiences()->toArray());
        sort($titles);
        $this->assertSame(['Женщины', 'Мужчины'], $titles);
    }

    public function testFeminineStyleWordDoesNotMatchAudience(): void
    {
        $brand = $this->brand('feminine-style-brand', 'Коллекция подчёркивает женственный силуэт и лёгкие ткани.');

        $this->tester->execute(['--id' => (string) $brand->getId()]);

        $this->tester->assertCommandIsSuccessful();
        $this->em->refresh($brand);
        $this->assertCount(0, $brand->getAudiences(), '"женственн" — про стиль, не про аудиторию');
    }

    public function testSecondRunDoesNotDuplicateLinks(): void
    {
        $brand = $this->brand('repeat-brand', 'Бренд шьёт детскую одежду для подростков.');

        $this->tester->execute(['--id' => (string) $brand->getId()]);
        $this->tester->assertCommandIsSuccessful();

        $this->tester->execute(['--id' => (string) $brand->getId()]);
        $this->tester->assertCommandIsSuccessful();

        $this->em->refresh($brand);
        $this->assertCount(1, $brand->getAudiences());

        $count = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM brand_audience_brand WHERE brand_id = ?',
            [$brand->getId()],
        );
        $this->assertSame(1, $count, 'Повторный прогон не должен плодить строки в join-таблице');
    }

    public function testDryRunDoesNotPersist(): void
    {
        $brand = $this->brand('dry-run-brand', 'Модная женская одежда больших размеров.');

        $this->tester->execute(['--id' => (string) $brand->getId(), '--dry-run' => true]);

        $this->tester->assertCommandIsSuccessful();
        $this->em->refresh($brand);
        $this->assertCount(0, $brand->getAudiences(), 'dry-run не должен писать связи');
        $this->assertStringContainsString('dry-run', $this->tester->getDisplay());
    }

    public function testDefaultSelectionSkipsAlreadyTaggedBrands(): void
    {
        $tagged = $this->brand('already-tagged', 'Женская одежда для стильных девушек.');
        $female = $this->em->getRepository(BrandAudience::class)->findOneBy(['title' => 'Женщины']);
        $tagged->addAudience($female);
        $this->em->flush();

        $untagged = $this->brand('not-tagged-yet', 'Женская одежда для стильных девушек.');

        $this->tester->execute(['--limit' => '500']);
        $this->tester->assertCommandIsSuccessful();

        $this->em->refresh($tagged);
        $this->em->refresh($untagged);
        $this->assertCount(1, $tagged->getAudiences(), 'Без --force уже размеченный бренд не трогаем повторно');
        $this->assertCount(1, $untagged->getAudiences());
    }
}
