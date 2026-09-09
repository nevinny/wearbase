<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ModerationTimeoutsCommand;
use App\Entity\Brand;
use App\Entity\BrandClaim;
use App\Entity\BrandModeration;
use App\Entity\BrandUser;
use App\Entity\Notification;
use App\Entity\User;
use App\Notification\AdminNotifier;
use App\Repository\BrandClaimRepository;
use App\Repository\BrandModerationRepository;
use App\Service\BrandActionSigner;
use App\Service\Moderation\ModerationOwnerNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:moderation:timeouts — четыре независимых правила таймаутов очереди премодерации
 * (см. докстринг команды). TG замокан AdminNotifier'ом.
 */
class ModerationTimeoutsCommandTest extends KernelTestCase
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
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    // ── а) reviewed без решения >2 дней — напоминание + троттлинг ────────────────────

    public function testRuleARemindsAndSetsRemindedAtThenSilentSameDay(): void
    {
        [, , $moderation] = $this->brandWithModeration(BrandModeration::STATUS_REVIEWED);
        $moderation->setAnalyzedAt(new \DateTime('-3 days'));
        $moderation->setMissing(['logo']);
        $this->em->flush();

        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->expects($this->once())->method('sendWithButtons');
        $tester = $this->tester($notifier);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->em->refresh($moderation);
        $this->assertNotNull($moderation->getRemindedAt(), 'reminded_at должен проставиться после напоминания');
        $this->assertSame(BrandModeration::STATUS_REVIEWED, $moderation->getStatus(), 'статус не должен меняться');

        // Повторный прогон в тот же день — sendWithButtons() уже был вызван once() выше,
        // повторный вызов уронил бы мок-ожидание.
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }

    public function testRuleADryRunSendsNothingAndWritesNothing(): void
    {
        [, , $moderation] = $this->brandWithModeration(BrandModeration::STATUS_REVIEWED);
        $moderation->setAnalyzedAt(new \DateTime('-3 days'));
        $this->em->flush();

        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->expects($this->never())->method('sendWithButtons');
        $tester = $this->tester($notifier);
        $tester->execute(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        $this->em->refresh($moderation);
        $this->assertNull($moderation->getRemindedAt());
    }

    // ── б) queued >48ч — списки в TG ──────────────────────────────────────────────

    public function testRuleBListsStalledQueue(): void
    {
        [$stuckBrand, , $stuck] = $this->brandWithModeration(BrandModeration::STATUS_QUEUED);
        $this->backdate('brand_moderation', (int) $stuck->getId(), ['created_at' => (new \DateTime('-50 hours'))->format('Y-m-d H:i:s')]);

        [$manualBrand, , $manual] = $this->brandWithModeration(BrandModeration::STATUS_QUEUED);
        $manual->setAnalyzeAttempts(3);
        $this->em->flush();
        $this->backdate('brand_moderation', (int) $manual->getId(), ['created_at' => (new \DateTime('-50 hours'))->format('Y-m-d H:i:s')]);

        // Свежая заявка (<48ч) не должна попасть в список.
        $this->brandWithModeration(BrandModeration::STATUS_QUEUED);

        $captured = null;
        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->expects($this->once())->method('send')->willReturnCallback(function (string $html) use (&$captured): void {
            $captured = $html;
        });
        $tester = $this->tester($notifier);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->assertNotNull($captured);
        $this->assertStringContainsString($stuckBrand->getTitle(), $captured);
        $this->assertStringContainsString($manualBrand->getTitle(), $captured);
        $this->assertStringContainsString('Анализатор стоит', $captured);
        $this->assertStringContainsString('ручная модерация', $captured);
    }

    // ── в) changes_requested без ответа владельца >14 дней — архивация ──────────────

    public function testRuleCArchivesOlderThan14DaysButNotAt13(): void
    {
        [$staleBrand, $staleOwner, $stale] = $this->brandWithModeration(BrandModeration::STATUS_CHANGES_REQUESTED);
        $stale->setDecidedAt(new \DateTime('-15 days'));
        $stale->setMissing(['logo']);

        [, , $fresh] = $this->brandWithModeration(BrandModeration::STATUS_CHANGES_REQUESTED);
        $fresh->setDecidedAt(new \DateTime('-13 days'));
        $this->em->flush();

        $notifier = $this->createMock(AdminNotifier::class);
        $tester = $this->tester($notifier);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->em->refresh($stale);
        $this->em->refresh($fresh);
        $this->assertSame(BrandModeration::STATUS_ARCHIVED, $stale->getStatus(), '>14 дней без ответа — архивируется');
        $this->assertSame(BrandModeration::STATUS_CHANGES_REQUESTED, $fresh->getStatus(), '13 дней — ещё не архивируется');

        $notification = $this->em->getRepository(Notification::class)->findOneBy([
            'recipient' => $staleOwner,
            'dedupeKey' => sprintf('moderation:%d:archived', $stale->getId()),
        ]);
        $this->assertNotNull($notification, 'владелец должен получить уведомление об архивации');
        $this->assertStringContainsString($staleBrand->getTitle(), (string) $notification->getTitle());
    }

    // ── г) BrandClaim pending/email_verified >2 дней — список в TG ──────────────────

    public function testRuleGListsOverdueClaims(): void
    {
        $overdue = $this->brandClaim(BrandClaim::STATUS_PENDING);
        $this->backdate('brand_claim', (int) $overdue->getId(), ['created_at' => (new \DateTime('-3 days'))->format('Y-m-d H:i:s')]);

        // Не должны попасть в список: свежая (<2 дней) и уже одобренная.
        $this->brandClaim(BrandClaim::STATUS_PENDING);
        $approved = $this->brandClaim(BrandClaim::STATUS_APPROVED);
        $this->backdate('brand_claim', (int) $approved->getId(), ['created_at' => (new \DateTime('-3 days'))->format('Y-m-d H:i:s')]);

        $captured = null;
        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->expects($this->once())->method('send')->willReturnCallback(function (string $html) use (&$captured): void {
            $captured = $html;
        });
        $tester = $this->tester($notifier);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->assertNotNull($captured);
        $this->assertStringContainsString('#' . $overdue->getId(), $captured);
        $this->assertStringContainsString($overdue->getBrand()->getTitle(), $captured);
        $this->assertStringContainsString((string) $overdue->getUser()->getEmail(), $captured);
    }

    // ── --dry-run глобально: ничего не пишет, TG молчит ──────────────────────────────

    public function testDryRunAcrossAllRulesChangesNothing(): void
    {
        [, , $reviewed] = $this->brandWithModeration(BrandModeration::STATUS_REVIEWED);
        $reviewed->setAnalyzedAt(new \DateTime('-3 days'));

        [, , $changesRequested] = $this->brandWithModeration(BrandModeration::STATUS_CHANGES_REQUESTED);
        $changesRequested->setDecidedAt(new \DateTime('-15 days'));
        $this->em->flush();

        $claim = $this->brandClaim(BrandClaim::STATUS_PENDING);
        $this->backdate('brand_claim', (int) $claim->getId(), ['created_at' => (new \DateTime('-3 days'))->format('Y-m-d H:i:s')]);

        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->expects($this->never())->method('send');
        $notifier->expects($this->never())->method('sendWithButtons');
        $tester = $this->tester($notifier);
        $tester->execute(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        $this->em->refresh($reviewed);
        $this->em->refresh($changesRequested);
        $this->assertNull($reviewed->getRemindedAt());
        $this->assertSame(BrandModeration::STATUS_CHANGES_REQUESTED, $changesRequested->getStatus());
    }

    // ── helpers ──────────────────────────────────────────────────────────────────

    /**
     * Команда собрана вручную (не через Application::find()): AdminNotifier уже проинициализирован
     * во время boot (см. AdminTelegramSubscriber) — контейнер тестов не даёт заменить сервис,
     * который уже был запрошен (TestContainer::set() бросает "already initialized"). Остальные
     * зависимости — настоящие сервисы из контейнера, как в ModerateTickCommandTest.
     */
    private function tester(AdminNotifier $notifier): CommandTester
    {
        $c = self::getContainer();

        return new CommandTester(new ModerationTimeoutsCommand(
            $this->em,
            $c->get(BrandModerationRepository::class),
            $c->get(BrandClaimRepository::class),
            $notifier,
            $c->get(BrandActionSigner::class),
            $c->get(ModerationOwnerNotifier::class),
        ));
    }

    /** @return array{0: Brand, 1: User, 2: BrandModeration} */
    private function brandWithModeration(string $status): array
    {
        $user = new User();
        $user->setEmail('owner-' . uniqid('', true) . '@example.com');
        $user->setPassword('hashed');
        $user->setRoles(['ROLE_BRAND_MANAGER']);
        $this->em->persist($user);

        $brand = new Brand();
        $brand->setTitle('Бренд ' . uniqid('', true));
        $brand->setSlug('brand-' . uniqid('', true));
        $brand->setStatus(Statuses::New);
        $this->em->persist($brand);

        $brandUser = new BrandUser();
        $brandUser->setUser($user);
        $brandUser->setBrand($brand);
        $brandUser->setRole(BrandUser::ROLE_OWNER);
        $this->em->persist($brandUser);

        $moderation = new BrandModeration();
        $moderation->setBrand($brand);
        $moderation->setStatus($status);
        $this->em->persist($moderation);

        $this->em->flush();

        return [$brand, $user, $moderation];
    }

    private function brandClaim(string $status): BrandClaim
    {
        $user = new User();
        $user->setEmail('claimant-' . uniqid('', true) . '@example.com');
        $user->setPassword('hashed');
        $user->setRoles(['ROLE_USER']);
        $this->em->persist($user);

        $brand = new Brand();
        $brand->setTitle('Бренд-claim ' . uniqid('', true));
        $brand->setSlug('brand-claim-' . uniqid('', true));
        $brand->setStatus(Statuses::New);
        $this->em->persist($brand);

        $claim = new BrandClaim();
        $claim->setBrand($brand);
        $claim->setUser($user);
        $claim->setStatus($status);
        $claim->setMethod(BrandClaim::METHOD_EMAIL_CODE);
        $this->em->persist($claim);

        $this->em->flush();

        return $claim;
    }

    private function backdate(string $table, int $id, array $fields): void
    {
        $this->em->getConnection()->update($table, $fields, ['id' => $id]);
    }
}
