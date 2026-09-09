<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ReferralEvent;
use App\Entity\ReferralRewardGrant;
use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeOutfit;
use App\Repository\ReferralRewardGrantRepository;
use App\Service\Referral\DisposableEmailDomains;
use App\Service\Referral\ReferralRewardService;
use App\Service\WardrobeAiAllowance;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Экономика реферальных наград: идемпотентность ledger'а, бары квалификации,
 * капы/потолки, антифрод-отсечки и эффективный лимит AI-квоты.
 *
 * Окно квалификации привязано к моменту регистрации (= createdAt referral_event),
 * поэтому вещь/образ в тестах создаётся ВСЕГДА после события.
 */
final class ReferralRewardServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ReferralRewardService $rewards;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->rewards = self::getContainer()->get(ReferralRewardService::class);
    }

    // ── Хелперы ──────────────────────────────────────────────────────────────

    private function user(string $email, bool $verified = false): User
    {
        $user = (new User())->setEmail($email)->setRoles(['ROLE_CUSTOMER'])->setPassword('test-password');
        if ($verified) {
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
        }
        $this->em->persist($user);

        return $user;
    }

    private function event(User $inviter, User $invitee): ReferralEvent
    {
        $event = new ReferralEvent($inviter, $invitee, ReferralEvent::SOURCE_LOOK_SHARE, null);
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    private function item(User $owner): WardrobeItem
    {
        $item = (new WardrobeItem())->setUser($owner)->setOriginalOwner($owner)->setItemNo(random_int(1, PHP_INT_MAX));
        $this->em->persist($item);

        return $item;
    }

    private function outfit(User $owner): WardrobeOutfit
    {
        $outfit = (new WardrobeOutfit())->setUser($owner)->setWardrobeOwner($owner);
        $this->em->persist($outfit);

        return $outfit;
    }

    private function flushActiveBump(User $user, int $amount, string $key): void
    {
        $this->em->persist(new ReferralRewardGrant(
            $user,
            ReferralRewardGrant::ROLE_INVITER,
            ReferralRewardGrant::KIND_AI_QUOTA_BUMP,
            $amount,
            $key,
            'test_seed',
            null,
            new \DateTimeImmutable('+7 days'),
        ));
        $this->em->flush();
    }

    // ── Тесты ────────────────────────────────────────────────────────────────

    public function testWelcomeGrantedOnceAndImmediately(): void
    {
        $inviter = $this->user('ref-inviter-once@test.local');
        $invitee = $this->user('ref-invitee-once@test.local');
        $this->em->flush();
        $event = $this->event($inviter, $invitee);

        $this->rewards->grantOnWelcome($event);
        $this->assertCount(2, $this->grants()->findBy(['user' => $invitee])); // бамп + бейдж

        // Повторный вызов (повторная доставка события, сбой между flush'ами) — no-op.
        $this->rewards->grantOnWelcome($event);
        $this->assertCount(2, $this->grants()->findBy(['user' => $invitee]));

        /** @var ReferralRewardGrant $bump */
        $bump = $this->grants()->findOneBy(['user' => $invitee, 'kind' => ReferralRewardGrant::KIND_AI_QUOTA_BUMP]);
        $this->assertSame(ReferralRewardGrant::WELCOME_DAILY_BUMP, $bump->getAmount());
        $this->assertSame('ref:'.$event->getId().':invitee:welcome', $bump->getIdempotencyKey());
        $this->assertSame(ReferralRewardGrant::STATUS_ACTIVE, $bump->getStatus());
        // Выдан сразу при регистрации: срок ≈ сейчас +30 дней.
        $this->assertEqualsWithDelta(
            (new \DateTimeImmutable('+30 days'))->getTimestamp(),
            $bump->getExpiresAt()?->getTimestamp(),
            60,
        );
    }

    public function testQualificationFailsWithoutVerifiedEmailOrAction(): void
    {
        $inviter = $this->user('ref-inviter-bar@test.local', true);
        $this->em->flush();

        // Email не подтверждён — бар не пройден, даже если есть вещь после регистрации.
        $unverified = $this->user('ref-invitee-bar1@test.local');
        $this->em->flush();
        $event1 = $this->event($inviter, $unverified);
        $this->item($unverified);
        $this->em->flush();
        $this->assertSame('bar_not_met', $this->rewards->qualifyAndGrant($event1));

        // Email подтверждён, но ни вещи, ни образа за окно — тоже не пройден.
        $idle = $this->user('ref-invitee-bar2@test.local', true);
        $this->em->flush();
        $this->assertSame('bar_not_met', $this->rewards->qualifyAndGrant($this->event($inviter, $idle)));
        $this->assertSame(
            0,
            $this->grants()->count(['role' => ReferralRewardGrant::ROLE_INVITER, 'user' => $inviter]),
        );

        // Подтверждённый email + образ после регистрации — квалификация проходит.
        $active = $this->user('ref-invitee-bar3@test.local', true);
        $this->em->flush();
        $activeEvent = $this->event($inviter, $active);
        $this->outfit($active);
        $this->em->flush();
        $this->assertSame('granted', $this->rewards->qualifyAndGrant($activeEvent));
        /** @var ReferralRewardGrant $grant */
        $grant = $this->grants()->findOneBy(['role' => ReferralRewardGrant::ROLE_INVITER, 'user' => $inviter]);
        $this->assertSame(ReferralRewardGrant::INVITER_DAILY_BUMP, $grant->getAmount());
        $this->assertSame('ref:'.$activeEvent->getId().':inviter:bump', $grant->getIdempotencyKey());
    }

    public function testMonthlyCapPaysOnlyFirstFiveQualified(): void
    {
        $inviter = $this->user('ref-inviter-cap@test.local', true);
        $this->em->flush();

        $lastOutcome = null;
        for ($i = 1; $i <= 6; ++$i) {
            $invitee = $this->user(sprintf('ref-cap-invitee-%d@test.local', $i), true);
            $this->em->flush();
            $event = $this->event($inviter, $invitee);
            $this->item($invitee); // первое действие уже ПОСЛЕ регистрации
            $this->em->flush();
            $lastOutcome = $this->rewards->qualifyAndGrant($event);
            if ($i <= 5) {
                $this->assertSame('granted', $lastOutcome);
            }
        }
        // Шестая квалификация считается, но гранта нет (решение PO №2).
        $this->assertSame('capped', $lastOutcome);
        $monthStart = new \DateTimeImmutable(date('Y-m').'-01T00:00:00');
        $this->assertSame(
            5,
            $this->grants()->countInviterBumpsBetween($inviter, $monthStart, $monthStart->modify('+1 month')),
        );
    }

    public function testDailyBumpCeilingClampsAndStops(): void
    {
        $inviter = $this->user('ref-inviter-ceiling@test.local', true);
        $this->flushActiveBump($inviter, 20, 'ref:test:ceiling-seed-1');
        $this->flushActiveBump($inviter, 8, 'ref:test:ceiling-seed-2'); // активных уже 28

        $invitee = $this->user('ref-ceiling-invitee@test.local', true);
        $this->em->flush();
        $event = $this->event($inviter, $invitee);
        $this->item($invitee);
        $this->em->flush();

        // Головroom 2 → грант ужимается до +2, сумма ровно 30 (потолок ≤+30/день).
        $this->assertSame('granted', $this->rewards->qualifyAndGrant($event));
        $this->assertSame(30, $this->grants()->sumActiveDailyBump($inviter));

        // Потолок достигнут — следующий квалифицированный друг гранта не получает.
        $another = $this->user('ref-ceiling-invitee2@test.local', true);
        $this->em->flush();
        $anotherEvent = $this->event($inviter, $another);
        $this->outfit($another);
        $this->em->flush();
        $this->assertSame('ceiling', $this->rewards->qualifyAndGrant($anotherEvent));
        $this->assertSame(30, $this->grants()->sumActiveDailyBump($inviter));
    }

    public function testManagedAndDisposableInviteesAreCutOff(): void
    {
        $inviter = $this->user('ref-inviter-guards@test.local', true);
        // Managed-ребёнок: синтетический домен family.wearbase.local (User::isManaged).
        $managed = $this->user('ref-managed-child@family.wearbase.local', true);
        $this->em->flush();

        $managedEvent = $this->event($inviter, $managed);
        $this->rewards->grantOnWelcome($managedEvent);
        $this->assertTrue($managed->isManaged());
        $this->assertSame(
            0,
            $this->grants()->count(['idempotencyKey' => 'ref:'.$managedEvent->getId().':invitee:welcome']),
        );

        // Одноразовый домен отсечён на этапе квалификации (спец §3).
        $disposable = $this->user('ref-disposable@test.mailinator.com', true);
        $this->em->flush();
        $disposableEvent = $this->event($inviter, $disposable);
        $this->item($disposable);
        $this->em->flush();
        $this->assertTrue(DisposableEmailDomains::isDisposable($disposable->getEmail()));
        $this->assertSame('disposable', $this->rewards->qualifyAndGrant($disposableEvent));
    }

    public function testVelocityTriggerSendsGrantToReview(): void
    {
        $inviter = $this->user('ref-inviter-review@test.local', true);
        $this->em->flush();

        // Кластер «мёртвых» приглашённых (без подтверждения и действия) в течение суток.
        $dead = ReferralRewardGrant::REVIEW_EVENTS_PER_24H - 1;
        for ($i = 1; $i <= $dead; ++$i) {
            $invitee = $this->user(sprintf('ref-review-dead-%d@test.local', $i));
            $this->em->flush();
            $this->assertSame('bar_not_met', $this->rewards->qualifyAndGrant($this->event($inviter, $invitee)));
        }
        $this->assertSame(0, $this->grants()->count(['role' => ReferralRewardGrant::ROLE_INVITER, 'user' => $inviter]));

        // Восьмое событие за 24ч — живой квалифицированный друг: грант выдаётся,
        // но в статусе review (очередь ручной проверки, решение PO №5).
        $good = $this->user('ref-review-good@test.local', true);
        $this->em->flush();
        $goodEvent = $this->event($inviter, $good);
        $this->item($good);
        $this->em->flush();
        $this->assertSame('review', $this->rewards->qualifyAndGrant($goodEvent));

        /** @var ReferralRewardGrant $grant */
        $grant = $this->grants()->findOneBy([
            'role' => ReferralRewardGrant::ROLE_INVITER,
            'user' => $inviter,
            'idempotencyKey' => 'ref:'.$goodEvent->getId().':inviter:bump',
        ]);
        $this->assertNotNull($grant);
        $this->assertSame(ReferralRewardGrant::STATUS_REVIEW, $grant->getStatus());
        // Review-грант НЕ увеличивает эффективный лимит (считаются только active).
        $allowance = self::getContainer()->get(WardrobeAiAllowance::class);
        $this->assertSame(WardrobeAiAllowance::BASE_DAILY_LIMIT, $allowance->effectiveLimit($inviter));
    }

    public function testAllowanceMathBasePlusGrantsCappedAtSixty(): void
    {
        $plain = $this->user('ref-allowance-plain@test.local');
        $boosted = $this->user('ref-allowance-boosted@test.local');
        $this->em->flush();

        $allowance = self::getContainer()->get(WardrobeAiAllowance::class);
        $this->assertSame(30, $allowance->effectiveLimit($plain));

        // Σ грантов 10+10+10+5 = 35 → потолок бампа +30 → лимит 60, а не 65.
        foreach ([10, 10, 10, 5] as $i => $amount) {
            $this->flushActiveBump($boosted, $amount, 'ref:test:allowance-seed-'.$i);
        }
        $this->assertSame(60, $allowance->effectiveLimit($boosted));
        $this->assertLessThanOrEqual(30, $allowance->remainingToday($plain));

        // Расход по базе: 30 списаний проходят, 31-е нет.
        for ($i = 0; $i < 30; ++$i) {
            $this->assertTrue($allowance->consume($plain), "consume #$i");
        }
        $this->assertFalse($allowance->consume($plain));

        // Буст расходуется поверх базы: 60 списаний, 61-е нет.
        for ($i = 0; $i < 60; ++$i) {
            $this->assertTrue($allowance->consume($boosted), "boosted #$i");
        }
        $this->assertFalse($allowance->consume($boosted));
        $this->assertSame(60, $this->usedRow((int) $boosted->getId()));
    }

    public function testExpireCommandTransitionsStatuses(): void
    {
        $user = $this->user('ref-expire@test.local');
        $this->em->flush();
        $due = new ReferralRewardGrant($user, ReferralRewardGrant::ROLE_INVITEE, ReferralRewardGrant::KIND_AI_QUOTA_BUMP, 10, 'ref:test:expire-due', 'welcome', null, new \DateTimeImmutable('-1 day'));
        $alive = new ReferralRewardGrant($user, ReferralRewardGrant::ROLE_INVITEE, ReferralRewardGrant::KIND_AI_QUOTA_BUMP, 5, 'ref:test:expire-alive', 'welcome', null, new \DateTimeImmutable('+7 days'));
        $this->em->persist($due);
        $this->em->persist($alive);
        $this->em->flush();

        $exit = (new CommandTester((new Application(self::$kernel))->find('app:referral:expire-grants')))->execute([]);
        $this->assertSame(0, $exit);
        $this->em->clear();

        $this->assertSame(ReferralRewardGrant::STATUS_EXPIRED, $this->em->find(ReferralRewardGrant::class, $due->getId())?->getStatus());
        $this->assertSame(ReferralRewardGrant::STATUS_ACTIVE, $this->em->find(ReferralRewardGrant::class, $alive->getId())?->getStatus());
    }

    public function testDashboardSummaryCountsInvitedBonusAndLazyBadges(): void
    {
        $inviter = $this->user('ref-dashboard-inviter@test.local', true);
        $this->em->flush();

        for ($i = 1; $i <= 3; ++$i) {
            $invitee = $this->user(sprintf('ref-dashboard-invitee-%d@test.local', $i), true);
            $this->em->flush();
            $event = $this->event($inviter, $invitee);
            $this->item($invitee); // после события — детерминированно в окне квалификации
            $this->em->flush();
            $this->rewards->qualifyAndGrant($event);
        }

        $summary = $this->rewards->dashboardSummary($inviter);
        $this->assertSame(3, $summary['invitedCount']);
        $this->assertSame(15, $summary['dailyBonus']); // 3 × +5/день
        $this->assertNotNull($summary['bonusUntil']);
        $this->assertSame(['current' => 3, 'target' => 10], $summary['badgeNext']);
        // Ленивый бейдж тира записан в ledger ровно один раз (идемпотентно при повторном просмотре).
        $this->rewards->dashboardSummary($inviter);
        $this->assertSame(1, $this->grants()->count(['idempotencyKey' => sprintf('ref:user:%d:inviter:tier:3', $inviter->getId())]));
    }

    // ── Внутреннее ───────────────────────────────────────────────────────────

    private function grants(): ReferralRewardGrantRepository
    {
        return self::getContainer()->get(ReferralRewardGrantRepository::class);
    }

    private function usedRow(int $userId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT requests FROM ai_daily_usage WHERE user_id = :u',
            ['u' => $userId],
        );
    }
}
