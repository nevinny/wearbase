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
    /** @var array<string, Wardrobe> */
    private array $defaultWardrobes = [];

    public function __construct(
        private readonly WardrobeRepository $wardrobes,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function getOrCreateDefault(User $owner): Wardrobe
    {
        $cacheKey = $this->ownerKey($owner);
        if (isset($this->defaultWardrobes[$cacheKey])) {
            return $this->defaultWardrobes[$cacheKey];
        }

        $wardrobe = $this->wardrobes->findDefaultForOwner($owner);
        if ($wardrobe !== null) {
            return $this->defaultWardrobes[$cacheKey] = $wardrobe;
        }

        $wardrobe = (new Wardrobe())->setOwner($owner);
        $this->entityManager->persist($wardrobe);

        return $this->defaultWardrobes[$cacheKey] = $wardrobe;
    }

    public function forgetDefault(User $owner): void
    {
        unset($this->defaultWardrobes[$this->ownerKey($owner)]);
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

    /**
     * «В архив» — только из active. Вещь с терминальным статусом (sold/donated/lost)
     * туда уже не переводим: это перезаписало бы факт продажи/дарения молча.
     */
    public function archive(WardrobeItem $item): bool
    {
        if ($item->getItemStatus() !== WardrobeItem::ITEM_ACTIVE) {
            return false;
        }
        $item->setItemStatus(WardrobeItem::ITEM_ARCHIVED);
        $this->entityManager->flush();

        return true;
    }

    /**
     * «Вернуть» — только из archived. Терминальные статусы (sold/donated/lost) не
     * восстанавливаем этой кнопкой — их меняют осознанно через форму редактирования.
     */
    public function restore(WardrobeItem $item): bool
    {
        if ($item->getItemStatus() !== WardrobeItem::ITEM_ARCHIVED) {
            return false;
        }
        $item->setItemStatus(WardrobeItem::ITEM_ACTIVE);
        $this->entityManager->flush();

        return true;
    }

    private function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    private function ownerKey(User $owner): string
    {
        return $owner->getId() !== null ? 'id:' . $owner->getId() : 'object:' . spl_object_id($owner);
    }
}
