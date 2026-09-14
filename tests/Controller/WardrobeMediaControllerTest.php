<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Wardrobe;
use App\Entity\WardrobeOutfit;
use App\Service\Wardrobe\WardrobeOutfitCollageRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Раздача коллажа образа «на утро» (WardrobeMediaController::outfit) — фото гардероба
 * приватные (docs/testing.md), доступ проверяется тем же FamilyService, что у остальных
 * маршрутов account_wardrobe_media_*.
 */
final class WardrobeMediaControllerTest extends WebTestCase
{
    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$owner, $outfit] = $this->makeDailyOutfitWithCollage($em);

        $client->request('GET', '/account/wardrobe/media/outfit/' . $outfit->getId());

        self::assertResponseRedirects();
    }

    public function testOwnerGetsTheCollageFile(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$owner, $outfit] = $this->makeDailyOutfitWithCollage($em);

        $client->loginUser($owner);
        $client->request('GET', '/account/wardrobe/media/outfit/' . $outfit->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('image/jpeg', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testOtherUserCannotAccessSomeoneElsesCollage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [, $outfit] = $this->makeDailyOutfitWithCollage($em);
        $stranger = UserFactory::withEmail(static::getContainer(), 'outfit-media-stranger-' . uniqid('', true) . '@test.local');

        $client->loginUser($stranger);
        $client->request('GET', '/account/wardrobe/media/outfit/' . $outfit->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testSoftDeletedOutfitIs404EvenForOwner(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$owner, $outfit] = $this->makeDailyOutfitWithCollage($em);
        // Soft-delete тем же путём, что и реальный ночной батч (WardrobeOutfitRepository::softDeleteDailyBatch),
        // а не прямой записью в приватное поле — нет и не должно быть публичного setDeletedAt().
        $today = new \DateTimeImmutable('today');
        static::getContainer()->get(\App\Repository\WardrobeOutfitRepository::class)
            ->softDeleteDailyBatch($owner, WardrobeOutfit::OCCASION_WORK, $today, $today->modify('+1 day'));
        // DQL bulk UPDATE не трогает identity map — без clear() контроллер (та же не
        // перезагруженная между запросами EM) получил бы закэшированный $outfit с deletedAt=null.
        $em->clear();

        $client->loginUser($owner);
        $client->request('GET', '/account/wardrobe/media/outfit/' . $outfit->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testMissingCollageFileIs404(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = UserFactory::withEmail(static::getContainer(), 'outfit-media-nofile-' . uniqid('', true) . '@test.local');
        $wardrobe = (new Wardrobe())->setOwner($owner);
        $em->persist($wardrobe);
        $outfit = (new WardrobeOutfit())
            ->setUser($owner)
            ->setWardrobeOwner($owner)
            ->setOccasion(WardrobeOutfit::OCCASION_WORK)
            ->setTitle('Образ без коллажа')
            ->setItems([]);
        $em->persist($outfit);
        $em->flush();

        $client->loginUser($owner);
        $client->request('GET', '/account/wardrobe/media/outfit/' . $outfit->getId());

        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{0: \App\Entity\User, 1: WardrobeOutfit} */
    private function makeDailyOutfitWithCollage(EntityManagerInterface $em): array
    {
        $owner = UserFactory::withEmail(static::getContainer(), 'outfit-media-owner-' . uniqid('', true) . '@test.local');
        $wardrobe = (new Wardrobe())->setOwner($owner);
        $em->persist($wardrobe);
        $outfit = (new WardrobeOutfit())
            ->setUser($owner)
            ->setWardrobeOwner($owner)
            ->setOccasion(WardrobeOutfit::OCCASION_WORK)
            ->setTitle('Образ на работу')
            ->setItems([]);
        $em->persist($outfit);
        $em->flush();

        /** @var WardrobeOutfitCollageRenderer $renderer */
        $renderer = static::getContainer()->get(WardrobeOutfitCollageRenderer::class);
        $path = $renderer->path((int) $outfit->getId());
        @mkdir(dirname($path), 0775, true);
        $im = imagecreatetruecolor(10, 10);
        imagejpeg($im, $path, 80);
        imagedestroy($im);

        return [$owner, $outfit];
    }
}
