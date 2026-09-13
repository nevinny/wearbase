<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Entity\WardrobeOutfit;
use App\Repository\WardrobeOutfitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WardrobeOutfitRepositoryTest extends KernelTestCase
{
    public function testFindDailyForOwnerExcludesInteractiveOtherDaysAndSoftDeleted(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repo = self::getContainer()->get(WardrobeOutfitRepository::class);
        $em->beginTransaction();

        try {
            $owner = $this->user($em, 'daily-owner');
            $today = new \DateTimeImmutable('today');

            $batchToday = $this->outfit($em, $owner, WardrobeOutfit::OCCASION_WORK, $today->modify('+1 hour'));
            $this->outfit($em, $owner, null, $today->modify('+2 hours')); // интерактивный — без повода
            $this->outfit($em, $owner, WardrobeOutfit::OCCASION_WALK, $today->modify('-1 hour')); // вчера
            $batchDeleted = $this->outfit($em, $owner, WardrobeOutfit::OCCASION_MEETING, $today->modify('+3 hours'));
            $em->flush();

            // Гасим один из сегодняшних батчей тем же способом, что и прод-конвейер.
            $repo->softDeleteDailyBatch($owner, WardrobeOutfit::OCCASION_MEETING, $today, $today->modify('+1 day'));
            $em->clear();

            $result = $repo->findDailyForOwner($owner, $today);

            self::assertCount(1, $result);
            self::assertSame($batchToday->getId(), $result[0]->getId());
        } finally {
            $em->rollback();
        }
    }

    public function testFindRecentReactedExcludesSoftDeleted(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repo = self::getContainer()->get(WardrobeOutfitRepository::class);
        $em->beginTransaction();

        try {
            $owner = $this->user($em, 'reacted-owner');
            $outfit = $this->outfit($em, $owner, WardrobeOutfit::OCCASION_WORK, new \DateTimeImmutable());
            $outfit->react(WardrobeOutfit::REACTION_LIKE);
            $em->flush();

            self::assertCount(1, $repo->findRecentReacted($owner));

            $repo->softDeleteDailyBatch($owner, WardrobeOutfit::OCCASION_WORK, new \DateTimeImmutable('today'), new \DateTimeImmutable('tomorrow'));
            $em->clear();

            self::assertCount(0, $repo->findRecentReacted($owner));
        } finally {
            $em->rollback();
        }
    }

    public function testFindActiveForOwnerRejectsWrongOwnerAndSoftDeleted(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repo = self::getContainer()->get(WardrobeOutfitRepository::class);
        $em->beginTransaction();

        try {
            $owner = $this->user($em, 'active-owner');
            $stranger = $this->user($em, 'active-stranger');
            $outfit = $this->outfit($em, $owner, WardrobeOutfit::OCCASION_WORK, new \DateTimeImmutable());
            $em->flush();
            $id = $outfit->getId();
            self::assertNotNull($id);

            self::assertNotNull($repo->findActiveForOwner($id, $owner));
            self::assertNull($repo->findActiveForOwner($id, $stranger));

            $repo->softDeleteDailyBatch($owner, WardrobeOutfit::OCCASION_WORK, new \DateTimeImmutable('today'), new \DateTimeImmutable('tomorrow'));
            $em->clear();

            self::assertNull($repo->findActiveForOwner($id, $owner));
        } finally {
            $em->rollback();
        }
    }

    public function testCountCreatedByUserBetweenExcludesBatchOutfits(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repo = self::getContainer()->get(WardrobeOutfitRepository::class);
        $em->beginTransaction();

        try {
            $user = $this->user($em, 'referral-count');
            $from = new \DateTimeImmutable('-1 day');
            $to = new \DateTimeImmutable('+1 day');

            $this->outfit($em, $user, WardrobeOutfit::OCCASION_WORK, new \DateTimeImmutable());
            $em->flush();
            self::assertSame(0, $repo->countCreatedByUserBetween($user, $from, $to));

            $this->outfit($em, $user, null, new \DateTimeImmutable());
            $em->flush();
            self::assertSame(1, $repo->countCreatedByUserBetween($user, $from, $to));
        } finally {
            $em->rollback();
        }
    }

    private function user(EntityManagerInterface $em, string $prefix): User
    {
        $user = (new User())
            ->setEmail($prefix.'-'.uniqid('', true).'@test.local')
            ->setPassword('test')
            ->setRoles(['ROLE_CUSTOMER']);
        $em->persist($user);

        return $user;
    }

    private function outfit(EntityManagerInterface $em, User $owner, ?string $occasion, \DateTimeImmutable $createdAt): WardrobeOutfit
    {
        $outfit = (new WardrobeOutfit())
            ->setUser($owner)
            ->setWardrobeOwner($owner)
            ->setTitle('Тестовый образ')
            ->setOccasion($occasion);
        (new \ReflectionProperty(WardrobeOutfit::class, 'createdAt'))->setValue($outfit, $createdAt);
        $em->persist($outfit);

        return $outfit;
    }
}
