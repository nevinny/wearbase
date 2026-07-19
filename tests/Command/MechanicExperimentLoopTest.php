<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\MechanicExperiment;
use App\Repository\MechanicExperimentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Петля экспериментов над механиками (docs/mechanic_experiments.md): propose (ICE-выбор) +
 * evaluate (diff-in-diff когорт A/B) + --start (снимок baseline).
 *
 * В test-БД сырая gsc_page_stats НЕ провижинится (SchemaTool видит только сущности) — это и есть
 * «env пуст»: замер обязан graceful no-op, а --start — снять нулевой baseline, не падая.
 * DiD-математику на реальных данных гоняем dev-DB dry-run'ом, не в phpunit.
 */
class MechanicExperimentLoopTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testProposePicksHighestIceFirst(): void
    {
        self::assertSame(0, $this->exec('app:experiment:propose', []));

        /** @var MechanicExperimentRepository $repo */
        $repo = self::getContainer()->get(MechanicExperimentRepository::class);
        $exp  = $repo->findByCode('single_cta');

        self::assertNotNull($exp, 'первым по ICE должен быть single_cta (7·6·8=336)');
        self::assertSame(MechanicExperiment::STATUS_PROPOSED, $exp->getStatus());
        self::assertSame(336, $exp->getIceScore());
    }

    public function testStartCapturesBaselineAndSetsWindow(): void
    {
        $exp = $this->makeProposed('start_probe');
        $this->em->flush();

        self::assertSame(0, $this->exec('app:experiment:propose', ['--start' => (string) $exp->getId()]));

        $this->em->refresh($exp);
        self::assertSame(MechanicExperiment::STATUS_RUNNING, $exp->getStatus());
        self::assertNotNull($exp->getStartedAt());
        self::assertNotNull($exp->getEndsAt());

        $result = $exp->getResultJson();
        self::assertArrayHasKey('baseline', $result);
        self::assertSame(0.0, $result['baseline']['a']['value'], 'env пуст → нулевой baseline, без падения');
    }

    public function testEvaluateNoOpWhenGscAbsent(): void
    {
        // Running-эксперимент с истёкшим окном, но gsc_page_stats в test отсутствует → замер
        // не должен судить по нулям (ложный rollback), а корректно уйти в no-op со статусом running.
        $exp = $this->makeProposed('noop_probe')
            ->setStatus(MechanicExperiment::STATUS_RUNNING)
            ->setStartedAt(new \DateTime('-21 days'))
            ->setEndsAt(new \DateTime('-1 hour'))
            ->setResultJson(['period_days' => 21, 'metric' => 'search_ctr', 'baseline' => []]);
        $this->em->flush();

        self::assertSame(0, $this->exec('app:experiment:evaluate', []));

        $this->em->refresh($exp);
        self::assertSame(MechanicExperiment::STATUS_RUNNING, $exp->getStatus(), 'при недоступной gsc статус не меняется');
    }

    private function makeProposed(string $code): MechanicExperiment
    {
        $exp = (new MechanicExperiment())
            ->setCode($code)
            ->setHypothesis('test ' . $code)
            ->setTarget('test target')
            ->setMetric('search_ctr')
            ->setCohortA(['kind' => 'brand_ids', 'ids' => [1]])
            ->setCohortB(['kind' => 'brand_ids', 'ids' => [2]])
            ->setImpact(5)->setConfidence(5)->setEase(5)->setIceScore(125)
            ->setPeriodDays(21);
        $this->em->persist($exp);

        return $exp;
    }

    /** @param array<string,string> $args */
    private function exec(string $name, array $args): int
    {
        $command = (new Application(self::$kernel))->find($name);

        return (new CommandTester($command))->execute($args);
    }
}
