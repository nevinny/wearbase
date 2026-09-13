<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\WardrobeItem;
use App\Entity\WardrobeOutfit;
use App\Entity\WardrobeOutfitShare;
use App\Repository\WardrobeOutfitRepository;

/** Точечное покрытие фикса «findActive() вместо find()» (учёт soft-delete в шаринге). */
class WardrobeOutfitShareControllerTest extends AuthenticatedWebTestCase
{
    public function testCreateShareSucceedsForLiveOutfit(): void
    {
        $client = static::createClient();
        // Отдельный пользователь: daily-образы живут в БД между тестами файла,
        // harness-customer накопил бы карточки из соседних тестов на той же витрине.
        $user = UserFactory::withEmail(static::getContainer(), 'share-live-outfit@test.local');
        $client->loginUser($user);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $item = (new WardrobeItem())->setUser($user)->setItemNo(1)->setName('Пальто')->setCategory('Пальто');
        $em->persist($item);
        $em->flush();
        $outfit = (new WardrobeOutfit())
            ->setUser($user)->setWardrobeOwner($user)->setOccasion(WardrobeOutfit::OCCASION_WORK)
            ->setTitle('Живой образ')->setItems([['id' => $item->getId(), 'category' => 'Пальто', 'color' => null, 'styles' => []]]);
        $em->persist($outfit);
        $em->flush();

        $crawler = $client->request('GET', '/account/wardrobe/outfits');
        $form = $crawler->filter('form[action*="/'.$outfit->getId().'/share"]')->form();
        $client->submit($form);

        self::assertResponseRedirects('/account/wardrobe/outfits');
        self::assertNotNull($em->getRepository(WardrobeOutfitShare::class)->findOneBy(['outfit' => $outfit]));
    }

    public function testCreateShareReturns404ForSoftDeletedOutfit(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), 'share-deleted-outfit@test.local');
        $client->loginUser($user);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $item = (new WardrobeItem())->setUser($user)->setItemNo(1)->setName('Свитер')->setCategory('Свитеры');
        $em->persist($item);
        $em->flush();
        $outfit = (new WardrobeOutfit())
            ->setUser($user)->setWardrobeOwner($user)->setOccasion(WardrobeOutfit::OCCASION_MEETING)
            ->setTitle('Образ на встречу')->setItems([['id' => $item->getId(), 'category' => 'Свитеры', 'color' => null, 'styles' => []]]);
        $em->persist($outfit);
        $em->flush();
        $outfitId = $outfit->getId();

        // Токен снимаем, пока образ ещё жив (форма рендерится только для живых образов).
        $crawler = $client->request('GET', '/account/wardrobe/outfits');
        $token = (string) $crawler->filter('form[action*="/'.$outfitId.'/share"] input[name="_token"]')->attr('value');
        self::assertNotSame('', $token);

        $today = new \DateTimeImmutable('today');
        static::getContainer()->get(WardrobeOutfitRepository::class)
            ->softDeleteDailyBatch($user, WardrobeOutfit::OCCASION_MEETING, $today, $today->modify('+1 day'));

        $client->request('POST', '/account/wardrobe/outfits/'.$outfitId.'/share', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
    }
}
