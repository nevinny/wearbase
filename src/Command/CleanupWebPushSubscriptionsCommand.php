<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\WebPushSubscriptionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:notifications:cleanup-web-push', description: 'Удаляет отозванные Web Push подписки старше 30 дней')]
final class CleanupWebPushSubscriptionsCommand extends Command
{
    public function __construct(private readonly WebPushSubscriptionRepository $subscriptions)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->subscriptions->deleteRevokedBefore(new \DateTimeImmutable('-30 days'));
        $output->writeln(sprintf('Deleted %d revoked Web Push subscription(s).', $count));

        return Command::SUCCESS;
    }
}
