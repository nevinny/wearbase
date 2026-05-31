<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Brand;
use App\Entity\Subscription;
use App\Entity\Tariff;
use App\Repository\SubscriptionRepository;
use App\Repository\TariffRepository;
use Doctrine\ORM\EntityManagerInterface;

class SubscriptionFactory
{
    public function __construct(
        private TariffRepository $tariffRepo,
        private SubscriptionRepository $subscriptionRepo,
        private EntityManagerInterface $em,
    ) {}

    public function createFreeTrial(Brand $brand): Subscription
    {
        $existing = $this->subscriptionRepo->findActiveByBrand($brand);
        if ($existing !== null) {
            return $existing;
        }

        $tariff = $this->tariffRepo->findOneByCode(Tariff::CODE_FREE);
        \assert($tariff !== null, 'Free tariff not found. Run migrations.');

        $now = new \DateTimeImmutable();
        $subscription = new Subscription();
        $subscription->setBrand($brand);
        $subscription->setTariff($tariff);
        $subscription->setStatus(Subscription::STATUS_TRIAL);
        $subscription->setCurrentPeriodStart($now);
        $subscription->setCurrentPeriodEnd($now->modify('+1 month'));
        $subscription->setTrialEndsAt($now->modify(\sprintf('+%d days', $tariff->getTrialDays())));

        $this->em->persist($subscription);

        return $subscription;
    }
}
