<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Brand;
use Doctrine\ORM\EntityManagerInterface;

/**
 * HTTP-семантика жизненного цикла бренда на публичной странице /ru/brands/{slug}:
 *  - deleted (мягко удалён)        → 410 Gone
 *  - active + closed_at (tombstone)→ 200 + плашка «прекратил работу»
 *  - new (в очереди дрип-публикации)→ 404
 *
 * Run: php bin/phpunit --filter NicheLifecycle
 */
class NicheLifecycleControllerTest extends DatabaseDependentWebTestCase
{
    /** @var int[] slug-фикстуры на удаление в tearDown */
    private array $fixtureIds = [];

    protected function tearDown(): void
    {
        if ($this->fixtureIds !== []) {
            $em = static::getContainer()->get('doctrine.orm.entity_manager');
            foreach ($this->fixtureIds as $id) {
                if (($b = $em->find(Brand::class, $id)) !== null) {
                    $em->remove($b);
                }
            }
            $em->flush();
            $this->fixtureIds = [];
        }
        parent::tearDown();
    }

    public function testDeletedBrandReturns410(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();
        $slug = $this->makeBrand($client->getContainer()->get('doctrine.orm.entity_manager'),
            'niche-test-deleted', fn (Brand $b) => $b->softDelete());

        $client->request('GET', "/ru/brands/$slug");

        $this->assertResponseStatusCodeSame(410);
    }

    public function testClosedActiveBrandReturns200WithTombstone(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();
        $slug = $this->makeBrand($client->getContainer()->get('doctrine.orm.entity_manager'),
            'niche-test-closed', fn (Brand $b) => $b->close());

        $client->request('GET', "/ru/brands/$slug");

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Бренд прекратил работу');
    }

    public function testNewBrandReturns404(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();
        $slug = $this->makeBrand($client->getContainer()->get('doctrine.orm.entity_manager'),
            'niche-test-new', fn (Brand $b) => $b->queue());

        $client->request('GET', "/ru/brands/$slug");

        $this->assertResponseStatusCodeSame(404);
    }

    /** Создаёт бренд с уникальным slug, применяет $mutate, сохраняет и регистрирует на очистку. */
    private function makeBrand(EntityManagerInterface $em, string $prefix, callable $mutate): string
    {
        $slug = $prefix . '-' . substr(md5(uniqid('', true)), 0, 8);
        $brand = (new Brand())->setTitle('Niche Test ' . $slug)->setSlug($slug);
        $mutate($brand);
        $em->persist($brand);
        $em->flush();
        $this->fixtureIds[] = $brand->getId();

        return $slug;
    }
}
