<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Referral\ReferralRewardService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Истечение бонусов (решение PO №7): активные гранты с наступившей expires_at → expired.
 * Неиспользованные дни сгорают без переноса. Cron-команда, ежедневный прогон.
 */
#[AsCommand(name: 'app:referral:expire-grants', description: 'Переводит истёкшие реферальные гранты в статус expired')]
final class ReferralExpireGrantsCommand extends Command
{
    public function __construct(
        private readonly ReferralRewardService $rewards,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expired = $this->rewards->expireDueGrants();
        $output->writeln(sprintf('Expired: %d', $expired));

        return Command::SUCCESS;
    }
}
