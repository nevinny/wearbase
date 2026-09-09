<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Notification;
use App\Entity\PurchaseRequest;
use App\Entity\PurchaseRequestItem;
use App\Entity\User;
use App\Notification\NotificationDispatcher;
use App\Repository\PurchaseRequestItemRepository;
use App\Repository\PurchaseRequestRepository;
use App\Service\FamilyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:family:purchase-reminders', description: 'Создаёт in-app напоминания по семейным покупкам, требующим действия')]
final class FamilyPurchaseRemindersCommand extends Command
{
    private const TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly PurchaseRequestRepository $requests,
        private readonly PurchaseRequestItemRepository $items,
        private readonly FamilyService $families,
        private readonly NotificationDispatcher $notifications,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать количество без записи')
            ->addOption('now', null, InputOption::VALUE_REQUIRED, 'Текущее время для воспроизводимого запуска (ISO-8601)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $now = $this->now($input->getOption('now'));
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());
            return Command::INVALID;
        }

        $localDay = $now->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d');
        $cutoff = $now->setTimezone(new \DateTimeZone(self::TIMEZONE))
            ->setTime(0, 0)
            ->setTimezone(new \DateTimeZone('UTC'));
        $pending = $this->requests->findPendingCreatedBefore($cutoff);
        $delivered = $this->items->findDeliveredBefore($cutoff);

        if ($input->getOption('dry-run')) {
            $io->success(sprintf('Будет обработано: pending requests %d, delivered items %d', count($pending), count($delivered)));
            return Command::SUCCESS;
        }

        foreach ($pending as $request) {
            $this->remindParents($request, $localDay);
        }
        foreach ($delivered as $item) {
            $this->remindFitting($item, $localDay);
        }
        $this->em->flush();

        $io->success(sprintf('Обработано: pending requests %d, delivered items %d', count($pending), count($delivered)));
        return Command::SUCCESS;
    }

    private function remindParents(PurchaseRequest $request, string $localDay): void
    {
        $subject = $request->getSubject();
        if (!$subject instanceof User || $subject->getFamily()?->getId() !== $request->getFamily()?->getId()) {
            return;
        }
        foreach ($this->families->membersFor($subject) as $parent) {
            if (!$parent->isFamilyParent()) {
                continue;
            }
            $this->notifications->dispatchInAppOnce(
                $parent,
                Notification::TYPE_PURCHASE_DECISION_REMINDER,
                sprintf('purchase-reminder:decision:%d:%s:%d', $request->getId(), $localDay, $parent->getId()),
                sprintf('Запрос %s ждёт решения', $subject->getFirstName() ?: 'ребёнка'),
                null,
                ['url' => '/account/purchases/'.$request->getId()],
            );
        }
    }

    private function remindFitting(PurchaseRequestItem $item, string $localDay): void
    {
        $request = $item->getPurchaseRequest();
        $subject = $request?->getSubject();
        if (!$request instanceof PurchaseRequest || !$subject instanceof User || $subject->getFamily()?->getId() !== $request->getFamily()?->getId()) {
            return;
        }
        foreach ($this->families->membersFor($subject) as $recipient) {
            if (!$recipient->isFamilyParent() && ($recipient->getId() !== $subject->getId() || $recipient->isManaged())) {
                continue;
            }
            $this->notifications->dispatchInAppOnce(
                $recipient,
                Notification::TYPE_PURCHASE_FITTING_REMINDER,
                sprintf('purchase-reminder:fitting:%d:%s:%d', $item->getId(), $localDay, $recipient->getId()),
                sprintf('Пора отметить результат примерки для %s', $subject->getFirstName() ?: 'ребёнка'),
                null,
                ['url' => '/account/purchases/'.$request->getId()],
            );
        }
    }

    private function now(mixed $value): \DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Опция --now должна быть строкой ISO-8601');
        }
        $now = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$now || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException('Некорректное значение --now');
        }

        return $now;
    }
}
