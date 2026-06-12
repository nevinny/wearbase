<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\EmailNotifier;
use App\Repository\NewsletterSubscriberRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Дайджест подписчикам рассылки: бренды, добавленные за период, + счётчик скидок.
 * Шлётся только подтверждённым и не отписанным (double opt-in), в каждом письме
 * ссылка отписки. Если за период нет ни новых брендов, ни скидок — не шлём ничего.
 *
 *   php bin/console app:newsletter:send-digest --dry-run   # кому и что уйдёт
 *   php bin/console app:newsletter:send-digest --days=7    # период (по умолчанию 7)
 *
 * Крон (еженедельно, понедельник 10:00): 0 10 * * 1
 */
#[AsCommand(name: 'app:newsletter:send-digest', description: 'Send newsletter digest (new brands + sales) to confirmed subscribers')]
class SendNewsletterDigestCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NewsletterSubscriberRepository $subscribers,
        private readonly Connection $db,
        private readonly EmailNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Period in days', '7')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would be sent without sending');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, (int) $input->getOption('days'));
        $dryRun = (bool) $input->getOption('dry-run');
        $since = new \DateTimeImmutable("-{$days} days");

        $brands = $this->em->createQuery(
            'SELECT b FROM App\Entity\Brand b
             WHERE b.status = :status AND b.created_at >= :since
             ORDER BY b.created_at DESC'
        )
            ->setParameter('status', Statuses::Active)
            ->setParameter('since', $since)
            ->setMaxResults(20)
            ->getResult();

        $saleCount = (int) $this->db->fetchOne(
            'SELECT COUNT(DISTINCT p.id)
             FROM product p
             JOIN product_variant v ON v.product_id = p.id
             WHERE p.status = :status AND v.compare_price IS NOT NULL AND v.compare_price > v.price',
            ['status' => Statuses::Active->value],
        );

        if (\count($brands) === 0 && $saleCount === 0) {
            $io->info("Nothing to send: no new brands in {$days} days and no sales.");

            return Command::SUCCESS;
        }

        $recipients = $this->subscribers->findActive();
        $io->text(sprintf('New brands: %d, products on sale: %d, recipients: %d', \count($brands), $saleCount, \count($recipients)));

        if ($dryRun) {
            foreach ($recipients as $s) {
                $io->text('  would send to ' . $s->getEmail());
            }
            $io->success('Dry run, nothing sent.');

            return Command::SUCCESS;
        }

        $sent = 0;
        foreach ($recipients as $subscriber) {
            try {
                $this->notifier->send(
                    $subscriber->getEmail(),
                    'Новые бренды и скидки — WEARBASE',
                    'newsletter_digest',
                    ['subscriber' => $subscriber, 'brands' => $brands, 'saleCount' => $saleCount],
                );
                ++$sent;
            } catch (\Throwable $e) {
                $io->warning("Failed for {$subscriber->getEmail()}: {$e->getMessage()}");
            }
        }

        $io->success("Digest sent to {$sent} subscriber(s).");

        return Command::SUCCESS;
    }
}
