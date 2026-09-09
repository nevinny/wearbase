<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ReferralEvent;
use App\Repository\ReferralEventRepository;
use App\Repository\ReferralRewardGrantRepository;
use App\Service\Referral\ReferralRewardService;
use App\Notification\AdminNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Квалификация реферальных событий (спец §2, cron-ready, раз в час): сканирует
 * события без inviter-гранта, проверяет бар «email подтверждён И ≥1 вещь/образ
 * за 30 дней» и выдаёт награду приглашающей. События, не прошедшие бар, будут
 * пересмотрены на следующем прогоне — пока окно 30 дней не истекло.
 */
#[AsCommand(name: 'app:referral:qualify', description: 'Квалифицирует реферальные события и выдаёт гранты приглашающим')]
final class ReferralQualifyCommand extends Command
{
    public function __construct(
        private readonly ReferralEventRepository $events,
        private readonly ReferralRewardGrantRepository $grants,
        private readonly ReferralRewardService $rewards,
        private readonly AdminNotifier $adminNotifier,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pending = array_filter(
            $this->events->findBy([], ['id' => 'ASC']),
            fn (ReferralEvent $event): bool => !$this->grants->existsByIdempotencyKey(
                sprintf('ref:%d:inviter:bump', (int) $event->getId())
            ),
        );

        $counts = [];
        $reviewed = [];
        foreach ($pending as $event) {
            $outcome = $this->rewards->qualifyAndGrant($event);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            if ($outcome === 'review') {
                $reviewed[] = $event;
            }
        }

        foreach ($counts as $outcome => $count) {
            $output->writeln(sprintf('%s: %d', $outcome, $count));
        }
        if ($pending === []) {
            $output->writeln('Нет событий без inviter-гранта');
        }

        if ($reviewed !== []) {
            // Очередь ручной проверки (решение PO №5): админ разбирает в TG.
            $lines = [];
            foreach ($reviewed as $event) {
                $lines[] = sprintf('#%d inviter=%s invitee=%s', $event->getId(), $event->getInviter()->getEmail(), $event->getInvitee()->getEmail());
            }
            $this->adminNotifier->send("\xE2\x9A\xA0\xEF\xB8\x8F <b>Реферальные гранты на ревью</b>\n".implode("\n", $lines));
        }

        return Command::SUCCESS;
    }
}
