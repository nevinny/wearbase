<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\User;
use App\Repository\WardrobeConsentRepository;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeConsentService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class WardrobeConsentServiceTest extends TestCase
{
    public function testChildCannotGrantOwnPersonalization(): void
    {
        $child = $this->user(1, User::FAMILY_ROLE_CHILD);
        $service = new WardrobeConsentService(
            $this->createStub(WardrobeConsentRepository::class),
            $this->createStub(FamilyService::class),
            $this->createStub(EntityManagerInterface::class),
        );

        $this->expectException(AccessDeniedException::class);
        $service->grantPersonalization($child, $child);
    }

    public function testAdultCannotGrantConsentForAnotherAdult(): void
    {
        $service = new WardrobeConsentService(
            $this->createStub(WardrobeConsentRepository::class),
            $this->createStub(FamilyService::class),
            $this->createStub(EntityManagerInterface::class),
        );

        $this->expectException(AccessDeniedException::class);
        $service->grantPersonalization($this->user(1, null), $this->user(2, null));
    }

    private function user(int $id, ?string $familyRole): User
    {
        $user = (new User())->setFamilyRole($familyRole);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        return $user;
    }
}
