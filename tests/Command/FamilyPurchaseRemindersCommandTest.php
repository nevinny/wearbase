<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\FittingFeedback;
use App\Entity\Notification;
use App\Entity\PurchaseRequest;
use App\Entity\PurchaseRequestItem;
use App\Entity\User;
use App\Service\FamilyService;
use App\Service\PurchaseRequestService;
use App\Tests\Controller\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class FamilyPurchaseRemindersCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FamilyService $families;
    private PurchaseRequestService $purchases;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        try {
            $this->em->getConnection()->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable) {
            self::markTestSkipped('Database is not available.');
        }
        $this->families = self::getContainer()->get(FamilyService::class);
        $this->purchases = self::getContainer()->get(PurchaseRequestService::class);
    }

    public function testPendingReminderUsesMoscowDayBoundaryAndParentMatrix(): void
    {
        $firstParent = $this->user('pending-first');
        $secondParent = $this->user('pending-second');
        $this->families->acceptInvite($secondParent, $this->families->createInvite($firstParent, User::FAMILY_ROLE_PARENT));
        $child = $this->families->createChild($firstParent, 'Маша');
        $sibling = $this->families->createChild($firstParent, 'Лиза');
        $foreign = $this->user('pending-foreign');
        $due = $this->purchases->create($child, $child, 'https://shop.example.test/reminder/due', null);
        $notDue = $this->purchases->create($child, $child, 'https://shop.example.test/reminder/not-due', null);
        $this->setRequestCreatedAt($due, '2026-08-24 20:59:59');
        $this->setRequestCreatedAt($notDue, '2026-08-24 21:00:00');

        $this->execute(['--now' => '2026-08-25T00:30:00+03:00', '--dry-run' => true]);
        self::assertSame(0, $this->reminderCount($firstParent, Notification::TYPE_PURCHASE_DECISION_REMINDER));

        $this->execute(['--now' => '2026-08-25T00:30:00+03:00']);
        $this->execute(['--now' => '2026-08-25T23:30:00+03:00']);
        self::assertSame(1, $this->reminderCount($firstParent, Notification::TYPE_PURCHASE_DECISION_REMINDER));
        self::assertSame(1, $this->reminderCount($secondParent, Notification::TYPE_PURCHASE_DECISION_REMINDER));
        self::assertSame(0, $this->reminderCount($child, Notification::TYPE_PURCHASE_DECISION_REMINDER));
        self::assertSame(0, $this->reminderCount($sibling, Notification::TYPE_PURCHASE_DECISION_REMINDER));
        self::assertSame(0, $this->reminderCount($foreign, Notification::TYPE_PURCHASE_DECISION_REMINDER));

        $this->execute(['--now' => '2026-08-26T00:01:00+03:00']);
        // день 2 не в расписании (1, 3, 7) — новых напоминаний нет
        self::assertSame(1, $this->reminderCount($firstParent, Notification::TYPE_PURCHASE_DECISION_REMINDER));
        self::assertSame(1, $this->reminderCount($secondParent, Notification::TYPE_PURCHASE_DECISION_REMINDER));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scheduleProvider')]
    public function testPendingReminderFollowsSchedule(int $age, bool $expected): void
    {
        $parent = $this->user('sched-pending');
        $child = $this->families->createChild($parent, 'Нина');
        $request = $this->purchases->create($child, $child, 'https://shop.example.test/reminder/sched', null);
        $this->setRequestCreatedAt($request, '2026-08-01 09:00:00');

        $now = (new \DateTimeImmutable('2026-08-01T12:00:00+03:00'))->modify(sprintf('+%d days', $age));
        $this->execute(['--now' => $now->format(\DateTimeInterface::ATOM)]);
        self::assertSame($expected ? 1 : 0, $this->reminderCount($parent, Notification::TYPE_PURCHASE_DECISION_REMINDER));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scheduleProvider')]
    public function testFittingReminderFollowsSchedule(int $age, bool $expected): void
    {
        $parent = $this->user('sched-fitting');
        $child = $this->families->createChild($parent, 'Вера');
        $request = $this->deliveredRequest($parent, $child);
        /** @var PurchaseRequestItem $item */
        $item = $request->getItems()->first();
        $this->em->getConnection()->update('purchase_request_item', ['delivered_at' => '2026-08-01 09:00:00'], ['id' => $item->getId()]);
        $this->em->refresh($item);

        $now = (new \DateTimeImmutable('2026-08-01T12:00:00+03:00'))->modify(sprintf('+%d days', $age));
        $this->execute(['--now' => $now->format(\DateTimeInterface::ATOM)]);
        self::assertSame($expected ? 1 : 0, $this->reminderCount($parent, Notification::TYPE_PURCHASE_FITTING_REMINDER));
    }

    public static function scheduleProvider(): array
    {
        return [
            'day 1' => [1, true], 'day 2' => [2, false], 'day 3' => [3, true],
            'day 7' => [7, true], 'day 8' => [8, false], 'day 30' => [30, false],
        ];
    }

    public function testDeliveredReminderTargetsParentsAndActivatedSubjectThenStopsAfterFitting(): void
    {
        $firstParent = $this->user('fitting-first');
        $secondParent = $this->user('fitting-second');
        $this->families->acceptInvite($secondParent, $this->families->createInvite($firstParent, User::FAMILY_ROLE_PARENT));
        $child = $this->families->createChild($firstParent, 'Аня');
        $child->setEmail($this->email('fitting-child'))->setClaimedAt(new \DateTimeImmutable());
        $sibling = $this->families->createChild($firstParent, 'Оля');
        $request = $this->deliveredRequest($firstParent, $child);
        /** @var PurchaseRequestItem $item */
        $item = $request->getItems()->first();
        $this->em->getConnection()->update('purchase_request_item', ['delivered_at' => '2026-08-24 20:59:59'], ['id' => $item->getId()]);
        $this->em->refresh($item);

        $this->execute(['--now' => '2026-08-25T00:30:00+03:00']);
        foreach ([$firstParent, $secondParent, $child] as $recipient) {
            self::assertSame(1, $this->reminderCount($recipient, Notification::TYPE_PURCHASE_FITTING_REMINDER));
        }
        self::assertSame(0, $this->reminderCount($sibling, Notification::TYPE_PURCHASE_FITTING_REMINDER));

        $this->purchases->recordFitting($child, $request, $item, FittingFeedback::OUTCOME_REFUSED, null, null, [], 'Не подошло');
        $this->execute(['--now' => '2026-08-26T09:00:00+03:00']);
        foreach ([$firstParent, $secondParent, $child] as $recipient) {
            self::assertSame(1, $this->reminderCount($recipient, Notification::TYPE_PURCHASE_FITTING_REMINDER));
        }
    }

    public function testInvalidNowReturnsInvalidExitCode(): void
    {
        self::assertSame(Command::INVALID, $this->execute(['--now' => 'tomorrow'])->getStatusCode());
    }

    private function deliveredRequest(User $parent, User $child): PurchaseRequest
    {
        $request = $this->purchases->create($child, $child, 'https://shop.example.test/reminder/fitting', null);
        /** @var PurchaseRequestItem $item */
        $item = $request->getItems()->first();
        $this->purchases->decideItem($parent, $request, $item, PurchaseRequest::STATUS_APPROVED);
        $this->purchases->markOrdered($parent, $request, $item, null);
        $this->purchases->markDelivered($parent, $request, $item);

        return $request;
    }

    private function execute(array $options): CommandTester
    {
        $command = (new Application(self::$kernel))->find('app:family:purchase-reminders');
        $tester = new CommandTester($command);
        $tester->execute($options);

        return $tester;
    }

    private function setRequestCreatedAt(PurchaseRequest $request, string $createdAt): void
    {
        $this->em->getConnection()->update('purchase_request', ['created_at' => $createdAt], ['id' => $request->getId()]);
        $this->em->refresh($request);
    }

    private function reminderCount(User $recipient, string $type): int
    {
        return $this->em->getRepository(Notification::class)->count(['recipient' => $recipient, 'type' => $type]);
    }

    private function user(string $prefix): User
    {
        return UserFactory::withEmail(self::getContainer(), $this->email($prefix));
    }

    private function email(string $prefix): string
    {
        return sprintf('%s-%s@test.local', $prefix, bin2hex(random_bytes(6)));
    }
}
