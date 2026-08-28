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
        // Бренд может быть ещё не сброшен в БД (регистрация бренда персистит Brand и сразу
        // просит триал в одной транзакции). Doctrine не умеет биндить сущность без id в DQL и
        // бросает ORMInvalidArgumentException → регистрация падала в 500. Без id подписок
        // существовать не может, поэтому поиск дубля просто пропускаем.
        $existing = $brand->getId() !== null ? $this->subscriptionRepo->findActiveByBrand($brand) : null;
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
        // Период подписки и триал должны кончаться вместе: раньше период был жёстко
        // «+1 month», и при триале длиннее месяца они расходились.
        $trialEnd = $now->modify(\sprintf('+%d days', $tariff->getTrialDays()));
        $subscription->setCurrentPeriodStart($now);
        $subscription->setCurrentPeriodEnd($trialEnd);
        $subscription->setTrialEndsAt($trialEnd);

        $this->em->persist($subscription);

        return $subscription;
    }
}
