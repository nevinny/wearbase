<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemLifecycleEvent;
use App\Service\FamilyService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class WardrobeItemLifecycleService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FamilyService $families,
    ) {}

    public function sendToCare(User $actor, User $subject, WardrobeItem $item, string $type, ?string $provider, ?string $cost, ?string $note): WardrobeItemLifecycleEvent
    {
        $this->assertCanManage($actor, $subject, $item);
        if (!in_array($type, WardrobeItemLifecycleEvent::CARE_TYPES, true)) {
            throw new \InvalidArgumentException('Недопустимый вид ухода или ремонта');
        }

        return $this->em->wrapInTransaction(function () use ($actor, $subject, $item, $type, $provider, $cost, $note): WardrobeItemLifecycleEvent {
            $this->em->refresh($item, LockMode::PESSIMISTIC_WRITE);
            if ($item->getItemStatus() !== WardrobeItem::ITEM_ACTIVE) {
                throw new \DomainException('Отправить в уход можно только активную вещь');
            }
            $event = new WardrobeItemLifecycleEvent($item, $subject, $actor, $type, $provider, $cost, $note);
            $item->setItemStatus(WardrobeItem::ITEM_REPAIR);
            $this->em->persist($event);
            $this->em->flush();
            return $event;
        });
    }

    public function completeCare(User $actor, User $subject, WardrobeItemLifecycleEvent $event): void
    {
        $item = $event->getItem();
        $this->assertCanManage($actor, $subject, $item);
        if ($event->getProfileSubject()->getId() !== $subject->getId()) {
            throw new AccessDeniedException('Событие не принадлежит гардеробу');
        }
        $this->em->wrapInTransaction(function () use ($event, $item): void {
            $this->em->refresh($event, LockMode::PESSIMISTIC_WRITE);
            $event->complete();
            if ($item->getItemStatus() === WardrobeItem::ITEM_REPAIR) {
                $item->setItemStatus(WardrobeItem::ITEM_ACTIVE);
            }
            $this->em->flush();
        });
    }

    public function transferOutside(User $actor, User $subject, WardrobeItem $item, ?string $recipient, ?string $note): WardrobeItemLifecycleEvent
    {
        $this->assertCanManage($actor, $subject, $item);
        if ($subject->getFamilyRole() === User::FAMILY_ROLE_CHILD && !$actor->isFamilyParent()) {
            throw new AccessDeniedException('Передачу детской вещи вне семьи подтверждает родитель');
        }
        return $this->em->wrapInTransaction(function () use ($actor, $subject, $item, $recipient, $note): WardrobeItemLifecycleEvent {
            $this->em->refresh($item, LockMode::PESSIMISTIC_WRITE);
            if (in_array($item->getItemStatus(), WardrobeItem::ARCHIVE_STATUSES, true)) {
                throw new \DomainException('Вещь уже выбыла из гардероба');
            }
            $event = new WardrobeItemLifecycleEvent($item, $subject, $actor, WardrobeItemLifecycleEvent::TYPE_TRANSFER_EXTERNAL, $recipient, null, $note);
            $item->setWearStatus(WardrobeItem::WEAR_GIVEN_AWAY);
            $item->setItemStatus(WardrobeItem::ITEM_DONATED);
            $this->em->persist($event);
            $this->em->flush();
            return $event;
        });
    }

    private function assertCanManage(User $actor, User $subject, WardrobeItem $item): void
    {
        if ($item->getUser()?->getId() !== $subject->getId() || !$this->families->canManage($actor, $subject)) {
            throw new AccessDeniedException('Нет доступа к вещи');
        }
    }
}
