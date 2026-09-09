<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Brand;
use App\Entity\BrandKeyword;
use App\Entity\BrandUser;
use App\Repository\BrandRepository;
use App\Tests\Controller\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * BrandRepository::findDripCandidateIds() — выборка кандидатов дрип-публикации
 * (app:brand:publish-tick, PR4 "владельческие бренды первыми").
 *
 * Тестируем выборку НАПРЯМУЮ через репозиторий, а не PublishTickCommand::execute():
 * та проверяет окно 9–22 МСК по wall-clock (тест был бы красным вечером/ночью), читает
 * PUBLISH_LAUNCH_DATE из env (не задан в test-окружении), держит file-лок и бросает
 * Bernoulli-монетку для n — ничего из этого не относится к SELECT-логике, которую
 * правит этот PR.
 *
 * SQLite (тест-БД) не знает MySQL-функций RAND()/CONCAT(), которые использует
 * репозиторий — регистрируем их как UDF на native-соединении (RAND — детерминированно).
 */
class PublishTickCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BrandRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(Brand::class);
        $this->em->beginTransaction();

        $pdo = $this->em->getConnection()->getNativeConnection();
        $pdo->sqliteCreateFunction('RAND', static fn () => 0.5);
        $pdo->sqliteCreateFunction('CONCAT', static fn (...$parts) => implode('', $parts));
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    private function makeBrand(string $title): Brand
    {
        $brand = (new Brand())->setTitle($title)->setSlug($title . '-' . uniqid());
        $this->em->persist($brand);

        return $brand;
    }

    /** @return int[] */
    private function findIds(int $limit = 50): array
    {
        return array_map('intval', $this->repo->findDripCandidateIds($limit));
    }

    // (а) владельческий бренд — первым, даже с нулевым спросом.
    public function testOwnerBrandWithNoDemandOutranksNonOwnerBrandWithDemand(): void
    {
        $owned      = $this->makeBrand('zarina')->queue();
        $withDemand = $this->makeBrand('befree')->queue();
        $this->em->flush();

        $owner = UserFactory::withEmail(self::getContainer(), 'publish-owner-' . uniqid() . '@test.local');
        $link  = (new BrandUser())->setUser($owner)->setBrand($owned)->setRole(BrandUser::ROLE_OWNER);
        $this->em->persist($link);

        $keyword = (new BrandKeyword())
            ->setBrand($withDemand)
            ->setKeyword('befree купить одежда')
            ->setMonthlyShows(1000);
        $this->em->persist($keyword);
        $this->em->flush();

        $ids = $this->findIds();

        $ownedPos   = array_search($owned->getId(), $ids, true);
        $demandPos  = array_search($withDemand->getId(), $ids, true);

        $this->assertNotFalse($ownedPos, 'владельческий бренд должен попасть в выборку');
        $this->assertNotFalse($demandPos, 'бренд со спросом должен попасть в выборку');
        $this->assertLessThan(
            $demandPos,
            $ownedPos,
            'владельческий бренд (даже с нулевым спросом) должен идти раньше бренда с ключевиками, но без владельца',
        );
    }

    // (б) niche_status='off' и origin_status foreign/unknown не попадают, NULL/'in'/'ru' — попадают.
    public function testNicheOffAndForeignOriginExcludedNullAndRuPass(): void
    {
        $offNiche = $this->makeBrand('offniche')->queue();
        $offNiche->markNiche('off', 'мебель', new \DateTime());

        $foreign = $this->makeBrand('foreignbrand')->queue();
        $foreign->markOrigin('foreign', 'nike', new \DateTime());

        $unknownOrigin = $this->makeBrand('unknownorigin')->queue();
        $unknownOrigin->markOrigin('unknown', 'сомнение', new \DateTime());

        $nullGates = $this->makeBrand('nullgates')->queue();

        $passingGates = $this->makeBrand('passinggates')->queue();
        $passingGates->markNiche('in', null, new \DateTime());
        $passingGates->markOrigin('ru', null, new \DateTime());

        $this->em->flush();

        $ids = $this->findIds();

        $this->assertNotContains($offNiche->getId(), $ids, "niche_status='off' должен быть исключён");
        $this->assertNotContains($foreign->getId(), $ids, "origin_status='foreign' должен быть исключён");
        $this->assertNotContains($unknownOrigin->getId(), $ids, "origin_status='unknown' должен быть исключён");
        $this->assertContains($nullGates->getId(), $ids, 'NULL niche/origin должны проходить');
        $this->assertContains($passingGates->getId(), $ids, "niche='in'/origin='ru' должны проходить");
    }

    // (в) status != 'new' или publish_pending=0 не попадают.
    public function testOnlyNewWithPendingFlagAreCandidates(): void
    {
        $notNewStatus = $this->makeBrand('alreadyactive');
        $notNewStatus->setStatus(Statuses::Active);
        $notNewStatus->setPublishPending(true);

        $notPending = $this->makeBrand('notpending');
        $notPending->setStatus(Statuses::New);
        // publishPending остаётся false (дефолт) — не в очереди

        $eligible = $this->makeBrand('eligible')->queue();

        $this->em->flush();

        $ids = $this->findIds();

        $this->assertNotContains($notNewStatus->getId(), $ids, "status != 'new' должен быть исключён");
        $this->assertNotContains($notPending->getId(), $ids, 'publish_pending=0 должен быть исключён');
        $this->assertContains($eligible->getId(), $ids);
    }
}
