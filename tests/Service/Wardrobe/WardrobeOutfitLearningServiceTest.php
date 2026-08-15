<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\User;
use App\Entity\WardrobeOutfit;
use App\Repository\WardrobeOutfitRepository;
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
        $service = new WardrobeOutfitLearningService($repository, $this->createStub(EntityManagerInterface::class));

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
        $service = new WardrobeOutfitLearningService($repository, $this->createStub(EntityManagerInterface::class));

        self::assertSame('', $service->context(new User()));
    }
}
