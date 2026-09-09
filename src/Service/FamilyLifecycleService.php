<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Family;
use App\Entity\FamilyMembershipEvent;
use App\Entity\User;
use App\Repository\WardrobeConsentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class FamilyLifecycleService
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly WardrobeConsentRepository $consents) {}

    public function confirmAdulthood(User $actor, User $child): void
    {
        if ($actor->getId() !== $child->getId()
            && (!$actor->isFamilyParent() || $actor->getFamily()?->getId() !== $child->getFamily()?->getId())
        ) {
            throw new AccessDeniedException('Нет доступа к профилю');
        }
        $family = $child->getFamily();
        if ($family === null) {
            throw new \DomainException('Профиль не состоит в семье');
        }
        if (!$child->canBecomeFamilyAdult()) {
            if ($child->isManaged()) {
                throw new \DomainException('Сначала активируйте личный вход в детский профиль');
            }
            throw new \DomainException('Переход доступен после 18-летия');
        }
        $this->em->wrapInTransaction(function () use ($actor, $child, $family): void {
            $this->em->refresh($child, LockMode::PESSIMISTIC_WRITE);
            $child->becomeFamilyAdult();
            $this->consents->findForSubject($child)?->revoke();
            $this->em->persist(new FamilyMembershipEvent($family, $actor, $child, FamilyMembershipEvent::TYPE_ADULTHOOD));
            $this->em->flush();
        });
    }

    public function transferOwnership(User $actor, User $newOwner): void
    {
        $family = $this->ownedFamily($actor);
        if ($newOwner->getFamily()?->getId() !== $family->getId() || !$newOwner->isFamilyParent() || $newOwner->getId() === $actor->getId()) {
            throw new \DomainException('Новым владельцем может стать другой родитель этой семьи');
        }
        $this->em->wrapInTransaction(function () use ($family, $actor, $newOwner): void {
            $this->em->refresh($family, LockMode::PESSIMISTIC_WRITE);
            $family->setOwner($newOwner);
            $this->em->persist(new FamilyMembershipEvent($family, $actor, $newOwner, FamilyMembershipEvent::TYPE_OWNER_TRANSFERRED));
            $this->em->flush();
        });
    }

    public function removeMember(User $actor, User $member): void
    {
        $family = $this->ownedFamily($actor);
        if ($member->getFamily()?->getId() !== $family->getId() || $member->getId() === $actor->getId()) {
            throw new \DomainException('Участника нельзя удалить');
        }
        $this->detach($family, $actor, $member, FamilyMembershipEvent::TYPE_MEMBER_REMOVED);
    }

    public function leave(User $actor): void
    {
        $family = $actor->getFamily();
        if ($family === null) {
            throw new \DomainException('Вы не состоите в семье');
        }
        if ($family->getOwner()?->getId() === $actor->getId()) {
            throw new \DomainException('Сначала передайте права владельца другому родителю');
        }
        $this->detach($family, $actor, $actor, FamilyMembershipEvent::TYPE_MEMBER_LEFT);
    }

    private function detach(Family $family, User $actor, User $member, string $type): void
    {
        $this->em->wrapInTransaction(function () use ($family, $actor, $member, $type): void {
            $this->em->refresh($member, LockMode::PESSIMISTIC_WRITE);
            $this->em->persist(new FamilyMembershipEvent($family, $actor, $member, $type));
            $member->setFamily(null)->setFamilyRole(null);
            $this->em->flush();
        });
    }

    private function ownedFamily(User $actor): Family
    {
        $family = $actor->getFamily();
        if ($family === null || $family->getOwner()?->getId() !== $actor->getId()) {
            throw new AccessDeniedException('Действие доступно владельцу семьи');
        }
        return $family;
    }
}
