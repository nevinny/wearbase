<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BrandUser;
use App\Entity\Notification;
use App\Entity\Subscription;
use App\Notification\NotificationDispatcher;
use App\Repository\BrandUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:subscription:expire')]
class SubscriptionExpireCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationDispatcher $dispatcher,
        private BrandUserRepository $brandUserRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Expire trial and active subscriptions that are past their end dates');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        // Trial → past_due
        $trialExpired = $this->em->createQueryBuilder()
            ->update(Subscription::class, 's')
            ->set('s.status', ':pastDue')
            ->where('s.status = :trial')
            ->andWhere('s.trialEndsAt < :now')
            ->setParameter('pastDue', Subscription::STATUS_PAST_DUE)
            ->setParameter('trial', Subscription::STATUS_TRIAL)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();

        // Active → expired
        $activeExpired = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Subscription::class, 's')
            ->where('s.status = :active')
            ->andWhere('s.currentPeriodEnd < :now')
            ->setParameter('active', Subscription::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();

        // Update status and notify owners
        foreach ($activeExpired as $subscription) {
            $subscription->setStatus(Subscription::STATUS_EXPIRED);
            $this->notifyBrandOwners($subscription);
        }

        $this->em->flush();

        $io->success(sprintf(
            'Expired: %d trials → past_due, %d active → expired',
            $trialExpired,
            count($activeExpired)
        ));

        return Command::SUCCESS;
    }

    private function notifyBrandOwners(Subscription $subscription): void
    {
        $brand = $subscription->getBrand();
        if (!$brand) {
            return;
        }

        /** @var BrandUser[] $owners */
        $owners = $this->brandUserRepo->findBy([
            'brand' => $brand,
            'role' => BrandUser::ROLE_OWNER,
        ]);

        foreach ($owners as $brandUser) {
            $user = $brandUser->getUser();
            if (!$user) {
                continue;
            }

            $this->dispatcher->dispatch(
                recipient: $user,
                type: Notification::TYPE_SYSTEM,
                title: 'Подписка истекла',
                body: sprintf(
                    'Подписка на бренд «%s» истекла. Продлите подписку для продолжения работы.',
                    $brand->getTitle() ?? $brand->getId(),
                ),
                data: ['brand_id' => $brand->getId(), 'subscription_id' => $subscription->getId()],
            );
        }
    }
}
