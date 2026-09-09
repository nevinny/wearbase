<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\FittingFeedback;
use App\Entity\User;
use App\Entity\WardrobeMemoryFact;
use App\Entity\WardrobeWearEvent;
use App\Repository\FittingFeedbackRepository;
use App\Repository\WardrobeConsentRepository;
use App\Repository\WardrobeMemoryFactRepository;
use App\Repository\WardrobeWearEventRepository;
use App\Service\FamilyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class WardrobeMemoryFactService
{
    public function __construct(
        private readonly WardrobeMemoryFactRepository $facts,
        private readonly WardrobeConsentRepository $consents,
        private readonly WardrobeWearEventRepository $wearEvents,
        private readonly FittingFeedbackRepository $fittings,
        private readonly FamilyService $families,
        private readonly EntityManagerInterface $em,
    ) {}

    public function rebuild(User $subject): void
    {
        if (!$this->consents->isPersonalizationGranted($subject)) {
            return;
        }
        foreach ($this->wearEvents->findRecentConfirmed($subject, 100) as $event) {
            $this->syncWear($event, false);
        }
        foreach ($this->fittings->findForSubject($subject) as $feedback) {
            $this->syncFitting($feedback, false);
        }
        $this->em->flush();
    }

    public function syncWear(WardrobeWearEvent $event, bool $flush = true): void
    {
        $subject = $event->getProfileSubject();
        $id = $event->getId();
        if ($id === null || !$this->consents->isPersonalizationGranted($subject)) {
            return;
        }
        if (!$event->isConfirmedWorn()) {
            $this->facts->findSource($subject, WardrobeMemoryFact::SOURCE_WEAR, $id)?->delete(false);
        } else {
            $parts = [];
            foreach ($event->getItems() as $eventItem) {
                $item = $eventItem->getItem();
                foreach ([$item->getCategory(), $item->getColorName()] as $value) {
                    if (is_string($value) && trim($value) !== '') {
                        $parts[] = trim($value);
                    }
                }
            }
            $label = match (true) {
                $event->getComfort() === 'uncomfortable', $event->wantsRepeat() === false => 'Не хочет повторять',
                $event->getComfort() === 'comfortable', $event->wantsRepeat() === true => 'Комфортно и хочет повторять',
                default => 'Подтверждённая носка',
            };
            $this->upsert($subject, $event->getActor(), WardrobeMemoryFact::SOURCE_WEAR, $id, $event->getSignalSource(), $label.': '.implode(', ', array_slice(array_unique($parts), 0, 12)));
        }
        if ($flush) {
            $this->em->flush();
        }
    }

    public function syncFitting(FittingFeedback $feedback, bool $flush = true): void
    {
        $item = $feedback->getItem();
        $subject = $item?->getPurchaseRequest()?->getSubject();
        $id = $feedback->getId();
        if (!$subject instanceof User || $id === null || !$this->consents->isPersonalizationGranted($subject)) {
            return;
        }
        if ($feedback->getOutcome() === FittingFeedback::OUTCOME_PENDING) {
            $this->facts->findSource($subject, WardrobeMemoryFact::SOURCE_FITTING, $id)?->delete(false);
        } else {
            $parts = array_filter([
                $feedback->getTriedSize() !== null ? 'размер '.$feedback->getTriedSize() : null,
                $feedback->getSizing() !== null ? 'посадка '.$feedback->getSizing() : null,
                'результат '.$feedback->getOutcome(),
            ]);
            $signalSource = $feedback->getActor()->getId() === $subject->getId()
                ? WardrobeMemoryFact::SIGNAL_SELF
                : WardrobeMemoryFact::SIGNAL_PARENT_OBSERVED;
            $this->upsert($subject, $feedback->getActor(), WardrobeMemoryFact::SOURCE_FITTING, $id, $signalSource, 'Примерка: '.implode(', ', $parts));
        }
        if ($flush) {
            $this->em->flush();
        }
    }

    public function deleteWear(WardrobeWearEvent $event): void
    {
        $id = $event->getId();
        if ($id !== null) {
            $this->facts->findSource($event->getProfileSubject(), WardrobeMemoryFact::SOURCE_WEAR, $id)?->delete(false);
            $this->em->flush();
        }
    }

    public function edit(User $actor, User $subject, int $id, string $fact): void
    {
        $memory = $this->owned($actor, $subject, $id);
        $memory->edit($fact);
        $this->em->flush();
    }

    /** @return WardrobeMemoryFact[] */
    public function list(User $actor, User $subject): array
    {
        $this->assertCanManage($actor, $subject);
        return $this->facts->findActive($subject);
    }

    public function delete(User $actor, User $subject, int $id): void
    {
        $this->owned($actor, $subject, $id)->delete();
        $this->em->flush();
    }

    public function deleteAll(User $actor, User $subject): void
    {
        $this->assertCanManage($actor, $subject);
        foreach ($this->facts->findActive($subject) as $fact) {
            $fact->delete();
        }
        $this->em->flush();
    }

    /** @return list<array<string, mixed>> */
    public function export(User $actor, User $subject): array
    {
        $this->assertCanManage($actor, $subject);
        return array_map(static fn (WardrobeMemoryFact $fact): array => [
            'id' => $fact->getId(),
            'fact' => $fact->getFact(),
            'source' => $fact->getSourceType(),
            'signal_source' => $fact->getSignalSource(),
            'actor_kind' => $fact->getActor()->getId() === $fact->getProfileSubject()->getId() ? 'self' : 'family_manager',
            'edited' => $fact->getEditedAt() !== null,
            'created_at' => $fact->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $fact->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->facts->findActive($subject));
    }

    public function context(User $subject): string
    {
        if (!$this->consents->isPersonalizationGranted($subject)) {
            return '';
        }
        $values = array_slice(array_map(static fn (WardrobeMemoryFact $fact): string => $fact->getFact(), $this->facts->findActive($subject)), 0, 20);
        return $values === [] ? '' : 'Редактируемая память пользователя: ['.implode('; ', $values).'].';
    }

    private function upsert(User $subject, User $actor, string $sourceType, int $sourceId, string $signalSource, string $value): void
    {
        $value = mb_substr($value, 0, 500);
        $fact = $this->facts->findSource($subject, $sourceType, $sourceId);
        if ($fact === null) {
            $this->em->persist(new WardrobeMemoryFact($subject, $actor, $sourceType, $sourceId, $signalSource, $value));
        } else {
            $fact->refresh($value);
        }
    }

    private function owned(User $actor, User $subject, int $id): WardrobeMemoryFact
    {
        $this->assertCanManage($actor, $subject);
        $fact = $this->facts->find($id);
        if (!$fact instanceof WardrobeMemoryFact || $fact->getProfileSubject()->getId() !== $subject->getId() || $fact->isDeleted()) {
            throw new \OutOfBoundsException('Факт не найден');
        }
        return $fact;
    }

    private function assertCanManage(User $actor, User $subject): void
    {
        if (!$this->families->canManage($actor, $subject)) {
            throw new AccessDeniedException('Нет доступа к персональной памяти');
        }
    }
}
