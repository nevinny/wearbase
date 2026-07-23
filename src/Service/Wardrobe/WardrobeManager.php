<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\User;
use App\Entity\Wardrobe;
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
}
