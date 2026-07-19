<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Brand;
use App\Entity\BrandStyle;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:outreach:warm-refresh (SALES-LOOP): выборка тёплых лидов из gsc_page_stats
 * (с дедупом дублей page_url-вариантов на день), драфты писем, идемпотентность
 * через outreach_log, no-op TG в тестовом окружении (ADMIN_TELEGRAM_CHAT_ID пуст).
 */
class OutreachWarmRefreshCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;
    private string $draftFile;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();

        // gsc_page_stats / outreach_log — сырые таблицы (нет Doctrine-сущности), SchemaTool
        // их не создаёт (см. tests/bootstrap.php). Создаём идемпотентно ДО транзакции теста,
        // чтобы DDL не откатился вместе с тестовыми данными.
        $this->db->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS gsc_page_stats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_url VARCHAR(512) NOT NULL,
                brand_id INTEGER DEFAULT NULL,
                day DATE NOT NULL,
                impressions INTEGER NOT NULL DEFAULT 0,
                clicks INTEGER NOT NULL DEFAULT 0,
                position DECIMAL(5,1) NOT NULL DEFAULT 0.0,
                query VARCHAR(255) DEFAULT NULL
            )
        SQL);
        $this->db->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS outreach_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                brand_id INTEGER NOT NULL,
                type VARCHAR(32) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'drafted',
                sent_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL
            )
        SQL);

        $this->em->beginTransaction();

        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $this->draftFile = $projectDir . '/var/outreach/warm-' . (new \DateTimeImmutable())->format('Y-m-d') . '.md';
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        if (is_file($this->draftFile)) {
            unlink($this->draftFile);
        }
        parent::tearDown();
    }

    private function makeBrand(string $slug, string $email, string $title = 'Brand', Statuses $status = Statuses::Active): Brand
    {
        $brand = (new Brand())
            ->setTitle($title)
            ->setSlug($slug)
            ->setEmail($email);
        $brand->setStatus($status);
        $this->em->persist($brand);

        return $brand;
    }

    public function testFindsWarmLeadDedupesClicksAndSkipsAlreadyDrafted(): void
    {
        $warm = $this->makeBrand(title: 'Тёплый бренд', slug: 'warm-brand', email: 'owner@warm-brand.ru');
        $cold = $this->makeBrand(title: 'Холодный бренд', slug: 'cold-brand', email: 'owner@cold-brand.ru');
        $alreadyDrafted = $this->makeBrand(title: 'Уже задрафтован', slug: 'already-drafted', email: 'owner@already.ru');
        $notActive = $this->makeBrand(title: 'Не активен', slug: 'not-active', email: 'owner@notactive.ru', status: Statuses::New);
        $this->em->flush();

        $warmId = $warm->getId();
        $coldId = $cold->getId();
        $draftedId = $alreadyDrafted->getId();
        $notActiveId = $notActive->getId();

        $today = new \DateTimeImmutable();
        $day1 = $today->modify('-5 days')->format('Y-m-d');
        $day2 = $today->modify('-2 days')->format('Y-m-d');
        $tooOld = $today->modify('-40 days')->format('Y-m-d'); // за пределами окна 28д

        $insertStat = function (int $brandId, string $pageUrl, string $day, int $clicks, int $impr): void {
            $this->db->executeStatement(
                'INSERT INTO gsc_page_stats (page_url, brand_id, day, impressions, clicks) VALUES (:u, :b, :d, :i, :c)',
                ['u' => $pageUrl, 'b' => $brandId, 'd' => $day, 'i' => $impr, 'c' => $clicks],
            );
        };

        // warm: день1 — два "варианта" адреса (утм-хвост) с одинаковыми цифрами → должны схлопнуться
        // MAX'ом (3/10), НЕ просуммироваться (иначе было бы 6/20). День2 — реально другой трафик,
        // суммируется аддитивно. Итог ожидания: клики 3+2=5, показы 10+5=15.
        $insertStat($warmId, '/ru/brands/warm-brand', $day1, 3, 10);
        $insertStat($warmId, '/ru/brands/warm-brand?utm_source=x', $day1, 3, 10);
        $insertStat($warmId, '/ru/brands/warm-brand', $day2, 2, 5);
        // за окном 28 дней — не должно влиять на сумму
        $insertStat($warmId, '/ru/brands/warm-brand', $tooOld, 99, 99);

        // cold: показы есть, кликов 0 — не тёплый (порог min-clicks=1 по умолчанию)
        $insertStat($coldId, '/ru/brands/cold-brand', $day1, 0, 5);

        // already-drafted: клики есть, но уже отмечен в outreach_log — должен быть исключён
        $insertStat($draftedId, '/ru/brands/already-drafted', $day1, 10, 20);
        $this->db->executeStatement(
            "INSERT INTO outreach_log (brand_id, type, status, created_at) VALUES (:b, 'warm_offer_draft', 'drafted', :now)",
            ['b' => $draftedId, 'now' => $today->format('Y-m-d H:i:s')],
        );

        // not-active: клики есть, но status != active — исключён
        $insertStat($notActiveId, '/ru/brands/not-active', $day1, 10, 20);

        $command = (new Application(self::$kernel))->find('app:outreach:warm-refresh');
        $tester  = new CommandTester($command);
        $exit    = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Тёплых лидов: 1', $tester->getDisplay());
        self::assertStringContainsString('Тёплый бренд', $tester->getDisplay());
        self::assertStringContainsString('клики 5', $tester->getDisplay());

        // outreach_log получил новую строку именно для тёплого бренда
        $logged = $this->db->fetchOne(
            "SELECT COUNT(*) FROM outreach_log WHERE brand_id = :b AND type = 'warm_offer_draft'",
            ['b' => $warmId],
        );
        self::assertSame(1, (int) $logged);

        // Драфт-файл создан и содержит правильные цифры + оффер
        self::assertFileExists($this->draftFile);
        $content = file_get_contents($this->draftFile);
        self::assertStringContainsString('Тёплый бренд', $content);
        self::assertStringContainsString('5 000₽', $content);
        self::assertStringContainsString('Анна Семянникова', $content);

        // Второй прогон: лид уже задрафтован — новых кандидатов быть не должно (идемпотентность)
        $tester2 = new CommandTester($command);
        $exit2   = $tester2->execute([]);
        self::assertSame(0, $exit2);
        self::assertStringContainsString('Новых тёплых лидов нет', $tester2->getDisplay());
    }

    public function testDryRunDoesNotWriteLogOrFile(): void
    {
        $brand = $this->makeBrand(title: 'Dry Brand', slug: 'dry-brand', email: 'owner@dry-brand.ru');
        $this->em->flush();
        $brandId = $brand->getId();

        $day = (new \DateTimeImmutable('-3 days'))->format('Y-m-d');
        $this->db->executeStatement(
            'INSERT INTO gsc_page_stats (page_url, brand_id, day, impressions, clicks) VALUES (:u, :b, :d, :i, :c)',
            ['u' => '/ru/brands/dry-brand', 'b' => $brandId, 'd' => $day, 'i' => 20, 'c' => 4],
        );

        $command = (new Application(self::$kernel))->find('app:outreach:warm-refresh');
        $tester  = new CommandTester($command);
        $exit    = $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Dry-run', $tester->getDisplay());

        $logged = $this->db->fetchOne(
            "SELECT COUNT(*) FROM outreach_log WHERE brand_id = :b",
            ['b' => $brandId],
        );
        self::assertSame(0, (int) $logged);
        self::assertFileDoesNotExist($this->draftFile);
    }
}
