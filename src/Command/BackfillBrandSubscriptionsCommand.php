<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BrandUser;
use App\Repository\BrandUserRepository;
use App\Repository\SubscriptionRepository;
use App\Service\SubscriptionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Создаёт free-trial подписку для брендов, у которых есть владелец, но нет
 * активной подписки (легаси-бренды, созданные до системы биллинга).
 *
 * Идемпотентно: SubscriptionFactory::createFreeTrial возвращает существующую
 * активную подписку, если она уже есть.
 *
 *   php bin/console app:subscription:backfill --dry-run
 *   php bin/console app:subscription:backfill
 */
#[AsCommand(
    name: 'app:subscription:backfill',
    description: 'Создаёт free-trial подписку для owner-брендов без активной подписки',
)]
class BackfillBrandSubscriptionsCommand extends Command
{
    public function __construct(
        private readonly BrandUserRepository    $brandUserRepo,
        private readonly SubscriptionRepository $subRepo,
        private readonly SubscriptionFactory    $subscriptionFactory,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать, без создания');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var BrandUser[] $owners */
        $owners = $this->brandUserRepo->findBy(['role' => BrandUser::ROLE_OWNER]);

        $brands = [];
        foreach ($owners as $owner) {
            $brand = $owner->getBrand();
            if ($brand !== null) {
                $brands[$brand->getId()] = $brand;
            }
        }

        $created = 0;
        foreach ($brands as $brand) {
            if ($this->subRepo->findActiveByBrand($brand) !== null) {
                continue;
            }
            $io->writeln(sprintf('%s бренд «%s» (#%d)', $dryRun ? '[dry-run]' : '+', $brand->getTitle(), $brand->getId()));
            if (!$dryRun) {
                $this->subscriptionFactory->createFreeTrial($brand);
            }
            $created++;
        }

        if (!$dryRun && $created > 0) {
            $this->em->flush();
        }

        $io->success(sprintf('%s: %d из %d owner-брендов', $dryRun ? 'Будет создано' : 'Создано', $created, count($brands)));

        return Command::SUCCESS;
    }
}
