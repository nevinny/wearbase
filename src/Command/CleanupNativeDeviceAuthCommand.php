<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\NativeDeviceSessionRepository;
use App\Repository\NativeRefreshTokenRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:native-auth:cleanup', description: 'Удаляет истёкшие native refresh receipts и завершённые device sessions')]
final class CleanupNativeDeviceAuthCommand extends Command
{
    public function __construct(
        private readonly NativeRefreshTokenRepository $refreshTokens,
        private readonly NativeDeviceSessionRepository $sessions,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $receipts = $this->refreshTokens->deleteExpired($now);
        $revoked = $this->sessions->deleteRevokedBefore($now);
        $expired = $this->sessions->deleteExpiredWithoutRefresh($now);
        $output->writeln(sprintf(
            'Deleted %d expired refresh receipt(s), %d revoked session(s), %d expired session(s).',
            $receipts,
            $revoked,
            $expired,
        ));

        return Command::SUCCESS;
    }
}
