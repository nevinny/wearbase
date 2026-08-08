<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PurchaseRequest;
use App\Entity\PurchaseRequestEvent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class PurchaseRequestService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function create(User $actor, User $subject, string $productUrl, ?string $comment): PurchaseRequest
    {
        $this->assertCanCreate($actor, $subject);

        $request = (new PurchaseRequest())
            ->setFamily($subject->getFamily())
            ->setSubject($subject)
            ->setCreatedBy($actor)
            ->setProductUrl($productUrl)
            ->setComment($comment !== null && trim($comment) !== '' ? trim($comment) : null);
        $request->addEvent(new PurchaseRequestEvent($actor, PurchaseRequestEvent::TYPE_CREATED));

        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    public function assertCanRead(User $actor, PurchaseRequest $request): void
    {
        if ($actor->getId() === $request->getSubject()?->getId()) {
            return;
        }

        if (!$actor->isFamilyParent()
            || $actor->getFamily() === null
            || $actor->getFamily()?->getId() !== $request->getFamily()?->getId()
        ) {
            throw new AccessDeniedException('Нет доступа к запросу на покупку');
        }
    }

    public function decide(User $actor, PurchaseRequest $request, string $decision, ?string $comment = null): void
    {
        $subject = $request->getSubject();
        if ($subject === null
            || !$actor->isFamilyParent()
            || $actor->getFamily() === null
            || $actor->getFamily()?->getId() !== $request->getFamily()?->getId()
            || $subject->getFamilyRole() !== User::FAMILY_ROLE_CHILD
        ) {
            throw new AccessDeniedException('Решение может принять только родитель этой семьи');
        }

        $request->decide($decision, $actor, $comment);
        $eventType = $decision === PurchaseRequest::STATUS_APPROVED
            ? PurchaseRequestEvent::TYPE_APPROVED
            : PurchaseRequestEvent::TYPE_REJECTED;
        $request->addEvent(new PurchaseRequestEvent($actor, $eventType));
        $this->em->flush();
    }

    private function assertCanCreate(User $actor, User $subject): void
    {
        if ($actor->getId() === $subject->getId()
            && $actor->getFamilyRole() === User::FAMILY_ROLE_CHILD
            && $actor->getFamily() !== null
        ) {
            return;
        }

        if ($actor->isFamilyParent()
            && $actor->getFamily() !== null
            && $actor->getFamily()?->getId() === $subject->getFamily()?->getId()
            && $subject->getFamilyRole() === User::FAMILY_ROLE_CHILD
        ) {
            return;
        }

        throw new AccessDeniedException('Запрос можно создать только для ребёнка своей семьи');
    }
}
