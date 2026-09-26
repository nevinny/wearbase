<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Brand;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:linkgraph:orphan-experiment — apply (свободный слот / вытеснение fill, control не трогаем)
 * и revert по журналу link_experiment_edge (docs/hadi_orphan_links.md).
 */
class OrphanLinkExperimentCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    public function testApplyUsesFreeSlotThenFillAndRevertRestores(): void
    {
        $free  = $this->brand('exp-free-donor');
        $full  = $this->brand('exp-full-donor');
        $treat = $this->brand('exp-treat');
        $ctrl  = $this->brand('exp-ctrl');
        $other = $this->brand('exp-other');

        // full-донор: 12 рёбер, одно из них fill → other
        for ($p = 1; $p <= 12; $p++) {
            $target = $p === 5 ? $other : $this->brand("exp-t{$p}");
            $this->db->insert('brand_related', [
                'brand_id' => $full->getId(), 'related_brand_id' => $target->getId(), 'position' => $p, 'source' => $p === 5 ? 'fill' : 'style',
            ]);
        }

        $plan = tempnam(sys_get_temp_dir(), 'plan');
        file_put_contents($plan, json_encode([
            'population' => [
                ['slug' => 'exp-treat', 'arm' => 'treatment', 'baseline_state' => 'URL is unknown to Google'],
                ['slug' => 'exp-ctrl', 'arm' => 'control', 'baseline_state' => ''],
            ],
            'edges' => [
                ['target' => 'exp-treat', 'donor' => 'exp-free-donor', 'tier' => 'free', 'score' => 0.71],
                ['target' => 'exp-treat', 'donor' => 'exp-full-donor', 'tier' => 'fill', 'score' => 0.65],
                ['target' => 'exp-ctrl', 'donor' => 'exp-free-donor', 'tier' => 'free', 'score' => 0.9],
            ],
        ]));

        $this->assertSame(Command::SUCCESS, $this->tester()->execute(['action' => 'apply', '--plan' => $plan, '--experiment' => 'exp-t']));

        $this->assertTrue($this->hasEdge($free, $treat), 'свободный слот донора → treatment');
        $this->assertTrue($this->hasEdge($full, $treat), 'fill-слот вытеснен под treatment');
        $this->assertFalse($this->hasEdge($full, $other), 'fill-ребро ушло');
        $this->assertFalse($this->hasEdge($free, $ctrl), 'control не получает входящих');
        $this->assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM link_experiment_edge WHERE experiment = 'exp-t'"));
        $this->assertSame('URL is unknown to Google', $this->db->fetchOne(
            "SELECT baseline_state FROM link_experiment WHERE experiment = 'exp-t' AND brand_id = :id", ['id' => $treat->getId()],
        ));

        $this->assertSame(Command::SUCCESS, $this->tester()->execute(['action' => 'revert', '--experiment' => 'exp-t']));

        $this->assertFalse($this->hasEdge($free, $treat));
        $this->assertTrue($this->hasEdge($full, $other), 'fill-ребро восстановлено');
        $this->assertSame('reverted', $this->db->fetchOne("SELECT DISTINCT verdict FROM link_experiment WHERE experiment = 'exp-t'"));
    }

    private function brand(string $slug): Brand
    {
        $b = (new Brand())->setTitle(strtoupper($slug))->setSlug($slug);
        $b->setStatus(Statuses::Active);
        $this->em->persist($b);
        $this->em->flush();

        return $b;
    }

    private function hasEdge(Brand $from, Brand $to): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT COUNT(*) FROM brand_related WHERE brand_id = :f AND related_brand_id = :t',
            ['f' => $from->getId(), 't' => $to->getId()],
        );
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find('app:linkgraph:orphan-experiment'));
    }
}
