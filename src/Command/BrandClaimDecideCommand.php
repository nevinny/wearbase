<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BrandClaim;
use App\Service\BrandClaimService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Решение по заявке на владение брендом (BrandClaim) с консоли прода (админ-UI требует
 * логина, а решения по заявкам иногда нужно принять прямо с консоли). Вся логика — в
 * BrandClaimService, чтобы консоль и админка не расходились в проверках.
 *
 *   php bin/console app:brand:claim-decide <claimId> approve [--note="..."]
 *   php bin/console app:brand:claim-decide <claimId> reject  [--note="..."]
 */
#[AsCommand(
    name: 'app:brand:claim-decide',
    description: 'Одобрить или отклонить заявку на владение брендом (BrandClaim) с консоли',
)]
class BrandClaimDecideCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BrandClaimService $claimService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('claimId', InputArgument::REQUIRED, 'ID заявки BrandClaim')
            ->addArgument('decision', InputArgument::REQUIRED, 'approve|reject')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Комментарий администратора');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $claimId  = (int) $input->getArgument('claimId');
        $decision = (string) $input->getArgument('decision');
        $note     = trim((string) $input->getOption('note'));

        if (!in_array($decision, ['approve', 'reject'], true)) {
            $io->error('decision должен быть approve или reject');
            return Command::INVALID;
        }

        $claim = $this->em->find(BrandClaim::class, $claimId);
        if ($claim === null) {
            $io->error("Заявка #{$claimId} не найдена");
            return Command::FAILURE;
        }

        return $decision === 'approve'
            ? $this->approve($claim, $note, $io)
            : $this->reject($claim, $note, $io);
    }

    private function approve(BrandClaim $claim, string $note, SymfonyStyle $io): int
    {
        if (!$this->claimService->isDecidable($claim)) {
            $io->error(sprintf('Заявка #%d уже обработана (статус: %s)', $claim->getId(), $claim->getStatus()));
            return Command::FAILURE;
        }

        $claim->setAdminNote($note !== '' ? $note : null);

        try {
            $this->claimService->grantOwnership($claim, null, 'admin');
        } catch (\DomainException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('%s → владелец бренда «%s»', $claim->getUser()->getEmail(), $claim->getBrand()->getTitle()));
        return Command::SUCCESS;
    }

    private function reject(BrandClaim $claim, string $note, SymfonyStyle $io): int
    {
        if (!$this->claimService->reject($claim, null, $note !== '' ? $note : null)) {
            $io->error(sprintf('Заявка #%d уже обработана (статус: %s)', $claim->getId(), $claim->getStatus()));
            return Command::FAILURE;
        }

        $io->success(sprintf('Заявка #%d отклонена', $claim->getId()));
        return Command::SUCCESS;
    }
}
