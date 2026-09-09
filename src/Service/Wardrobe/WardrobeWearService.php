<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeOutfit;
use App\Entity\WardrobeWearEvent;
use App\Repository\WardrobeItemRepository;
use App\Repository\WardrobeOutfitRepository;
use App\Repository\WardrobeWearEventRepository;
use App\Service\FamilyService;
use App\ValueObject\MoneyAmount;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class WardrobeWearService
{
    public function __construct(
        private readonly FamilyService $families,
        private readonly WardrobeItemRepository $items,
        private readonly WardrobeOutfitRepository $outfits,
        private readonly WardrobeWearEventRepository $events,
        private readonly EntityManagerInterface $em,
        private readonly ?WardrobeActivationService $activation = null,
    ) {}

    /** @param array<int, array{item:WardrobeItem,confidence:?string}> $candidates */
    public function createReview(User $actor, User $subject, array $candidates, ?\DateTimeImmutable $day = null, ?File $photo = null): WardrobeWearEvent
    {
        $this->assertCanManage($actor, $subject);
        $event = new WardrobeWearEvent($subject, $actor, WardrobeWearEvent::TYPE_WORN, $this->signalSource($actor, $subject), $day);
        $event->setPhotoFile($photo);
        foreach ($candidates as $candidate) {
            $this->assertOwnedItem($subject, $candidate['item']);
            $event->addItem($candidate['item'], 'vision', $candidate['confidence']);
        }
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    /** @param int[] $itemIds */
    public function confirm(User $actor, WardrobeWearEvent $event, array $itemIds, string $type, ?string $occasion, ?string $comment): void
    {
        $subject = $event->getProfileSubject();
        $this->assertCanManage($actor, $subject);
        if (!in_array($event->getStatus(), [WardrobeWearEvent::STATUS_REVIEW, WardrobeWearEvent::STATUS_CONFIRMED], true)
            || !in_array($type, WardrobeWearEvent::TYPES, true)
        ) {
            throw new \DomainException('Событие имеет неверный статус или тип');
        }
        foreach (array_unique(array_map('intval', $itemIds)) as $itemId) {
            $item = $this->items->findActiveOneForUser($itemId, $subject);
            if ($item === null) {
                throw new AccessDeniedException('Вещь не принадлежит выбранному гардеробу');
            }
            $event->addItem($item);
        }
        $event->setOccasion($occasion)->setComment($comment);
        if ($event->getStatus() === WardrobeWearEvent::STATUS_REVIEW) {
            $event->changeType($type);
            $event->confirm($itemIds);
        } else {
            $event->revise($type, $itemIds);
        }
        $this->em->flush();
        if ($type === WardrobeWearEvent::TYPE_WORN && $this->events->hasRepeatedItem($subject)) {
            $this->activation?->repeatWearRecorded($actor, $subject, 'wear_review');
        }
    }

    public function recordOutfitWorn(User $actor, User $subject, int $outfitId): WardrobeWearEvent
    {
        $this->assertCanManage($actor, $subject);
        return $this->em->wrapInTransaction(function () use ($actor, $subject, $outfitId): WardrobeWearEvent {
            $outfit = $this->em->find(WardrobeOutfit::class, $outfitId, LockMode::PESSIMISTIC_WRITE);
            if (!$outfit instanceof WardrobeOutfit
                || $outfit->getUser()?->getId() !== $actor->getId()
                || $outfit->getWardrobeOwner()?->getId() !== $subject->getId()
            ) {
                throw new \DomainException('Образ не найден');
            }
            $today = new \DateTimeImmutable('today');
            $existing = $this->events->findConfirmedForOutfitDay($subject, $outfit, $today);
            if ($existing !== null) {
                return $existing;
            }
            $event = (new WardrobeWearEvent($subject, $actor, WardrobeWearEvent::TYPE_WORN, $this->signalSource($actor, $subject), $today))
                ->setSourceOutfit($outfit);
            $ids = [];
            foreach ($outfit->getItems() as $snapshot) {
                $item = $this->items->findActiveOneForUser((int) ($snapshot['id'] ?? 0), $subject);
                if ($item !== null) {
                    $event->addItem($item, 'ai_outfit', 'high');
                    $ids[] = (int) $item->getId();
                }
            }
            $event->confirm($ids);
            $outfit->react(WardrobeOutfit::REACTION_WORN);
            $this->em->persist($event);
            $this->em->flush();
            if ($this->events->hasRepeatedItem($subject)) {
                $this->activation?->repeatWearRecorded($actor, $subject, 'outfit');
            }
            return $event;
        });
    }

    public function addFeedback(User $actor, WardrobeWearEvent $event, string $comfort, ?bool $wantsRepeat, ?string $comment): void
    {
        $this->assertCanManage($actor, $event->getProfileSubject());
        $event->addFeedback($comfort, $wantsRepeat, $comment);
        $this->em->flush();
    }

    public function delete(User $actor, WardrobeWearEvent $event): void
    {
        $this->assertCanManage($actor, $event->getProfileSubject());
        $this->em->remove($event);
        $this->em->flush();
    }

    /** @return array<int, array{wearCount:int,lastWorn:string,costPerWear:?string}> */
    public function statistics(User $subject): array
    {
        $result = [];
        foreach ($this->events->itemWearStats($subject) as $row) {
            $result[$row['itemId']] = [
                'wearCount' => $row['wearCount'],
                'lastWorn' => $row['lastWorn'],
                'costPerWear' => $row['price'] === null ? null : MoneyAmount::fromMinor((int) round(
                    MoneyAmount::toMinor($row['price']) / $row['wearCount'],
                )),
            ];
        }
        return $result;
    }

    private function assertCanManage(User $actor, User $subject): void
    {
        if (!$this->families->canManage($actor, $subject)) {
            throw new AccessDeniedException('Нет доступа к гардеробу');
        }
    }

    private function assertOwnedItem(User $subject, WardrobeItem $item): void
    {
        if ($item->getUser()?->getId() !== $subject->getId()) {
            throw new AccessDeniedException('Вещь не принадлежит выбранному гардеробу');
        }
    }

    private function signalSource(User $actor, User $subject): string
    {
        return $actor->getId() === $subject->getId() ? 'self' : 'parent_observed';
    }
}
