<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\User;
use App\Entity\Wardrobe;
use App\Entity\WardrobeItem;
use App\Repository\WardrobeRepository;
use Doctrine\ORM\EntityManagerInterface;

class WardrobeManager
{
    public function __construct(
        private readonly WardrobeRepository $wardrobes,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function getOrCreateDefault(User $owner): Wardrobe
    {
        $wardrobe = $this->wardrobes->findDefaultForOwner($owner);
        if ($wardrobe !== null) {
            return $wardrobe;
        }

        $wardrobe = (new Wardrobe())->setOwner($owner);
        $this->entityManager->persist($wardrobe);

        return $wardrobe;
    }

    public function refreshCompletionStatus(WardrobeItem $item): string
    {
        $hasName = $this->filled($item->getName());
        $hasCategory = $item->getCategoryRef() !== null || $this->filled($item->getCategory());
        $hasPhoto = $this->filled($item->getPhoto()) || $item->getPhotoFile() !== null;
        $hasPhotoOrUrl = $hasPhoto || $this->filled($item->getProductUrl());
        $hasIdentifier = $this->filled($item->getSize()) || $this->filled($item->getColorName());

        $status = WardrobeItem::COMPLETION_DRAFT;
        if ($hasName && $hasCategory && $hasPhotoOrUrl && $hasIdentifier) {
            $status = WardrobeItem::COMPLETION_BASIC;
        }

        $hasPurchase = $item->getPrice() !== null || $item->getPurchasedAt() !== null;
        if (
            $status === WardrobeItem::COMPLETION_BASIC
            && $hasPhoto
            && $this->filled($item->getSize())
            && $this->filled($item->getCustomBrandName())
            && $this->filled($item->getColorName())
            && $this->filled($item->getMaterialText())
            && $hasPurchase
            && $this->filled($item->getPurchaseReason())
            && $this->filled($item->getCareText())
        ) {
            $status = WardrobeItem::COMPLETION_COMPLETE;
        }

        $item->setCompletionStatus($status);

        return $status;
    }

    private function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
