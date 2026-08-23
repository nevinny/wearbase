<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\PurchaseRequest;
use App\Entity\PurchaseRequestEvent;
use App\Entity\PurchaseRequestItem;
use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Repository\WardrobeItemRepository;
use App\Service\FamilyService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class PurchaseToWardrobeService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FamilyService $families,
        private readonly WardrobeManager $wardrobes,
        private readonly WardrobeItemRepository $items,
    ) {}

    public function add(User $actor, PurchaseRequest $request, PurchaseRequestItem $item): WardrobeItem
    {
        $subject = $request->getSubject();
        if ($subject === null
            || $item->getPurchaseRequest()?->getId() !== $request->getId()
            || !$this->families->canManage($actor, $subject)
        ) {
            throw new AccessDeniedException('Нет доступа к покупке');
        }

        return $this->em->wrapInTransaction(function () use ($actor, $request, $item, $subject): WardrobeItem {
            $this->em->refresh($item, LockMode::PESSIMISTIC_WRITE);
            if ($item->getWardrobeItem() !== null) {
                return $item->getWardrobeItem();
            }
            if ($item->getStatus() !== PurchaseRequestItem::STATUS_BOUGHT) {
                throw new \DomainException('В гардероб можно добавить только выкупленную вещь');
            }
            $this->em->lock($subject, LockMode::PESSIMISTIC_WRITE);
            $wardrobeItem = (new WardrobeItem())
                ->setUser($subject)
                ->setOriginalOwner($subject)
                ->setWardrobe($this->wardrobes->getOrCreateDefault($subject))
                ->setItemNo($this->items->nextItemNo($subject))
                ->setName('Покупка из магазина')
                ->setProductUrl($item->getSourceUrl())
                ->setPrice($item->getActualPrice() ?? $item->getEstimatedPrice())
                ->setPurchasedAt(new \DateTimeImmutable('today'))
                ->setSize($item->getFittingFeedback()?->getTriedSize())
                ->setSource(WardrobeItem::SOURCE_WEB);
            $this->wardrobes->refreshCompletionStatus($wardrobeItem);
            $this->em->persist($wardrobeItem);
            $item->linkWardrobeItem($wardrobeItem);
            $request->addEvent((new PurchaseRequestEvent($actor, PurchaseRequestEvent::TYPE_ADDED_TO_WARDROBE))->setItem($item));
            $this->em->flush();

            return $wardrobeItem;
        });
    }
}
