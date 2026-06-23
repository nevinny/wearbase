<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Brand;
use App\Repository\BrandRepository;
use App\Service\BrandLinkGraphService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Направленный граф brand_related: гигиена мёртвых рёбер (обе стороны) и
 * рендер-фильтр findRelatedHard (только active-таргеты).
 */
class BrandRelatedGraphTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;
    private BrandLinkGraphService $graph;
    private BrandRepository $brands;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->graph = $c->get(BrandLinkGraphService::class);
        $this->brands = $c->get(BrandRepository::class);

        // brand_related — raw-таблица из миграции (не Doctrine-сущность), поэтому
        // schema:create её не строит из метаданных. Создаём для теста (SQLite).
        $this->db->executeStatement(
            'CREATE TABLE IF NOT EXISTS brand_related (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                brand_id INTEGER NOT NULL,
                related_brand_id INTEGER NOT NULL,
                position INTEGER NOT NULL,
                source VARCHAR(20) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    private function brand(string $slug, Statuses $status): Brand
    {
        $b = (new Brand())->setTitle(strtoupper($slug))->setSlug($slug);
        $b->setStatus($status);
        $this->em->persist($b);
        $this->em->flush();

        return $b;
    }

    private function edge(Brand $from, Brand $to, int $position): void
    {
        $this->db->executeStatement(
            'INSERT INTO brand_related (brand_id, related_brand_id, position, source) VALUES (:f, :t, :p, :s)',
            ['f' => $from->getId(), 't' => $to->getId(), 'p' => $position, 's' => 'fill'],
        );
    }

    private function outCount(Brand $b): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM brand_related WHERE brand_id = :id', ['id' => $b->getId()]);
    }

    private function hasEdge(Brand $from, Brand $to): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT COUNT(*) FROM brand_related WHERE brand_id = :f AND related_brand_id = :t',
            ['f' => $from->getId(), 't' => $to->getId()],
        );
    }

    public function testReplaceDeadEdgesRemovesEdgeToNonActiveTarget(): void
    {
        $a = $this->brand('graph-a', Statuses::Active);
        $b = $this->brand('graph-b', Statuses::Active);
        $n = $this->brand('graph-n', Statuses::New);

        $this->edge($a, $n, 1); // active → new (мёртвый таргет)
        $this->edge($a, $b, 2); // active → active (живой)

        $this->graph->replaceDeadEdges();

        $this->assertFalse($this->hasEdge($a, $n), 'ребро на new-таргет должно уйти');
        $this->assertTrue($this->hasEdge($a, $b), 'ребро на active-таргет должно остаться');
    }

    public function testReplaceDeadEdgesRemovesEdgeFromNonActiveSource(): void
    {
        // Сторона ИСТОЧНИКА — то, что добавили в этой сессии.
        $a = $this->brand('graph-a', Statuses::Active);
        $disabled = $this->brand('graph-d', Statuses::Disabled);

        $this->edge($disabled, $a, 1); // disabled → active (мёртвый источник)

        $this->graph->replaceDeadEdges();

        $this->assertSame(0, $this->outCount($disabled), 'исходящие рёбра non-active источника должны уйти');
    }

    public function testFindRelatedHardReturnsOnlyActiveTargets(): void
    {
        // Рендер-фильтр: блок «похожие» не должен отдавать ссылки на non-active бренды.
        $a = $this->brand('graph-a', Statuses::Active);
        $b = $this->brand('graph-b', Statuses::Active);
        $n = $this->brand('graph-n', Statuses::New);

        $this->edge($a, $b, 1);
        $this->edge($a, $n, 2);

        $related = $this->brands->findRelatedHard($a);
        $ids = array_map(static fn (Brand $x) => $x->getId(), $related);

        $this->assertContains($b->getId(), $ids, 'active-таргет присутствует');
        $this->assertNotContains($n->getId(), $ids, 'new-таргет отфильтрован');
    }
}
