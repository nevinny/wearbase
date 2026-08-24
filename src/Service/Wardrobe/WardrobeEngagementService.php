<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeWearEvent;
use App\Repository\WardrobeItemRepository;
use App\Repository\WardrobeWearEventRepository;

final class WardrobeEngagementService
{
    public function __construct(private readonly WardrobeItemRepository $items, private readonly WardrobeWearEventRepository $events) {}

    /** @return array{streak:int,totalWears:int,earned:array<int,array{icon:string,title:string}>,next:?array{title:string,current:int,target:int}} */
    public function summary(User $subject): array
    {
        $activeItems = $this->items->countActiveForUser($subject);
        $wearEvents = array_values(array_filter(
            $this->events->findRecentConfirmed($subject, 100),
            static fn (WardrobeWearEvent $event): bool => $event->isConfirmedWorn(),
        ));
        $stats = $this->events->itemWearStats($subject);
        $totalWears = count($wearEvents);
        $streak = $this->streak($wearEvents);
        $earned = [];
        if ($activeItems >= 5) {
            $earned[] = ['icon' => '🧩', 'title' => 'Первые 5 вещей'];
        }
        if ($totalWears >= 1) {
            $earned[] = ['icon' => '✨', 'title' => 'Первый образ'];
        }
        if (max(array_column($stats, 'wearCount') ?: [0]) >= 3) {
            $earned[] = ['icon' => '💜', 'title' => 'Любимая вещь'];
        }
        if ($streak >= 3) {
            $earned[] = ['icon' => '🔥', 'title' => '3 дня в образах'];
        }

        $next = match (true) {
            $activeItems < 5 => ['title' => 'Собрать первые 5 вещей', 'current' => $activeItems, 'target' => 5],
            $totalWears < 1 => ['title' => 'Отметить первый образ', 'current' => 0, 'target' => 1],
            max(array_column($stats, 'wearCount') ?: [0]) < 3 => ['title' => 'Найти любимую вещь', 'current' => max(array_column($stats, 'wearCount') ?: [0]), 'target' => 3],
            default => null,
        };

        return compact('streak', 'totalWears', 'earned', 'next');
    }

    /** @param WardrobeWearEvent[] $events */
    private function streak(array $events): int
    {
        $dates = [];
        foreach ($events as $event) {
            $dates[$event->getWornOn()->format('Y-m-d')] = true;
        }
        $cursor = new \DateTimeImmutable('today');
        if (!isset($dates[$cursor->format('Y-m-d')])) {
            $cursor = $cursor->modify('-1 day');
        }
        $streak = 0;
        while (isset($dates[$cursor->format('Y-m-d')])) {
            ++$streak;
            $cursor = $cursor->modify('-1 day');
        }
        return $streak;
    }
}
