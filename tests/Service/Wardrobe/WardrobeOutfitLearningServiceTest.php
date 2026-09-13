<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeOutfit;
use App\Repository\WardrobeOutfitRepository;
use App\Repository\WardrobeConsentRepository;
use App\Service\Wardrobe\WardrobeOutfitLearningService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class WardrobeOutfitLearningServiceTest extends TestCase
{
    public function testContextSeparatesPositiveAndNegativeSignals(): void
    {
        $user = new User();
        $worn = (new WardrobeOutfit())
            ->setUser($user)
            ->setWardrobeOwner($user)
            ->setItems([['id' => 1, 'category' => 'Рубашки', 'color' => 'Белый', 'styles' => ['Минимализм']]]);
        $worn->react(WardrobeOutfit::REACTION_WORN);
        $disliked = (new WardrobeOutfit())
            ->setUser($user)
            ->setWardrobeOwner($user)
            ->setItems([['id' => 2, 'category' => 'Каблуки', 'color' => 'Красный', 'styles' => []]]);
        $disliked->react(WardrobeOutfit::REACTION_DISLIKE);
        $repository = $this->createMock(WardrobeOutfitRepository::class);
        $repository->expects(self::once())->method('findRecentReacted')->with($user)->willReturn([$worn, $disliked]);
        $service = new WardrobeOutfitLearningService($repository, $this->createStub(EntityManagerInterface::class), null, null, $this->consents(true));

        $context = $service->context($user);

        self::assertStringContainsString('Рубашки', $context);
        self::assertStringContainsString('Минимализм', $context);
        self::assertStringContainsString('Каблуки', $context);
        self::assertStringContainsString('Красный', $context);
    }

    public function testContextIsEmptyWithoutFeedback(): void
    {
        $repository = $this->createStub(WardrobeOutfitRepository::class);
        $repository->method('findRecentReacted')->willReturn([]);
        $service = new WardrobeOutfitLearningService($repository, $this->createStub(EntityManagerInterface::class), null, null, $this->consents(true));

        self::assertSame('', $service->context(new User()));
    }

    public function testRevokedConsentStopsContextBeforeReadingHistory(): void
    {
        $repository = $this->createMock(WardrobeOutfitRepository::class);
        $repository->expects(self::never())->method('findRecentReacted');
        $service = new WardrobeOutfitLearningService(
            $repository,
            $this->createStub(EntityManagerInterface::class),
            null,
            null,
            $this->consents(false),
        );

        self::assertSame('', $service->context(new User()));
    }

    /** Владение — по wardrobeOwner (семейный сценарий: родитель управляет луком ребёнка). */
    public function testReactThrowsWhenRepositoryFindsNoActiveOutfitForOwner(): void
    {
        $owner = new User();
        $repository = $this->createMock(WardrobeOutfitRepository::class);
        $repository->expects(self::once())->method('findActiveForOwner')->with(42, $owner)->willReturn(null);
        $service = new WardrobeOutfitLearningService($repository, $this->createStub(EntityManagerInterface::class));

        $this->expectException(\DomainException::class);
        $service->react($owner, 42, WardrobeOutfit::REACTION_LIKE);
    }

    public function testReactAppliesReactionWhenOutfitBelongsToOwner(): void
    {
        $owner = new User();
        $outfit = (new WardrobeOutfit())->setUser($owner)->setWardrobeOwner($owner);
        $repository = $this->createMock(WardrobeOutfitRepository::class);
        $repository->method('findActiveForOwner')->with(7, $owner)->willReturn($outfit);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $service = new WardrobeOutfitLearningService($repository, $em);

        $service->react($owner, 7, WardrobeOutfit::REACTION_DISLIKE);

        self::assertSame(WardrobeOutfit::REACTION_DISLIKE, $outfit->getReaction());
    }

    private function consents(bool $granted): WardrobeConsentRepository
    {
        $consents = $this->createStub(WardrobeConsentRepository::class);
        $consents->method('isPersonalizationGranted')->willReturn($granted);
        return $consents;
    }
}
