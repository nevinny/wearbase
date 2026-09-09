<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeWearEvent;
use App\Service\FamilyService;
use App\Service\Wardrobe\WardrobeWearRecognitionService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class WardrobeWearControllerTest extends AuthenticatedWebTestCase
{
    public function testGuestIsRedirected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/wardrobe/wear');
        self::assertResponseRedirects('/login');
    }

    public function testInvalidCsrfAndDateDoNotCreateWearEvent(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), 'wear-invalid-input@test.local');
        $client->loginUser($user);
        $repository = static::getContainer()->get('doctrine.orm.entity_manager')->getRepository(WardrobeWearEvent::class);

        $client->request('POST', '/account/wardrobe/wear', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $repository->count(['profileSubject' => $user]));

        $crawler = $client->request('GET', '/account/wardrobe/wear');
        $client->request('POST', '/account/wardrobe/wear', [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'wornOn' => '2026-02-31',
        ]);
        self::assertResponseRedirects('/account/wardrobe/wear');
        self::assertSame(0, $repository->count(['profileSubject' => $user]));
    }

    public function testConfirmedWornCountsEverySelectedItemOnce(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), 'wear-confirmed@test.local');
        [$shirt, $trousers] = $this->items($user, ['Рубашка', 'Брюки'], ['100.00', '300.00']);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/account/wardrobe/wear');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $client->submit($crawler->selectButton('Без фото — выбрать вещи вручную')->form());
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        $event = static::getContainer()->get('doctrine.orm.entity_manager')->getRepository(WardrobeWearEvent::class)
            ->findOneBy(['profileSubject' => $user], ['id' => 'DESC']);
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/account/wardrobe/wear/'.$event->getId().'/confirm', [
            '_token' => $token,
            'items' => [(string) $shirt->getId(), (string) $trousers->getId()],
            'type' => WardrobeWearEvent::TYPE_WORN,
            'occasion' => 'Офис',
        ]);
        self::assertResponseRedirects('/account/wardrobe/wear');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('body', '100,00 ₽ / носку');
        self::assertSelectorTextContains('body', '300,00 ₽ / носку');
        $client->submit($crawler->selectButton('Сохранить впечатление')->form([
            'comfort' => 'comfortable',
            'repeat' => '1',
            'comment' => 'Хочу повторить с этой обувью',
        ]));
        self::assertResponseRedirects('/account/wardrobe/wear');

        $event = static::getContainer()->get('doctrine.orm.entity_manager')->find(WardrobeWearEvent::class, $event->getId());
        self::assertTrue($event->isConfirmedWorn());
        self::assertCount(2, $event->getItems());
        self::assertSame('Офис', $event->getOccasion());
        self::assertSame('comfortable', $event->getComfort());
        self::assertTrue($event->wantsRepeat());
        self::assertSame('Хочу повторить с этой обувью', $event->getComment());

        $crawler = $client->request('GET', '/account/wardrobe/wear/'.$event->getId());
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/account/wardrobe/wear/'.$event->getId().'/confirm', [
            '_token' => $token,
            'items' => [(string) $shirt->getId()],
            'type' => WardrobeWearEvent::TYPE_WORN,
        ]);
        $crawler = $client->followRedirect();
        self::assertStringNotContainsString('300,00 ₽ / носку', $crawler->html());
        $client->submit($crawler->selectButton('Удалить')->form());
        $crawler = $client->followRedirect();
        self::assertStringNotContainsString('100,00 ₽ / носку', $crawler->html());
    }

    public function testFittingDoesNotIncreaseWearCount(): void
    {
        $client = static::createClient();
        $user = UserFactory::withEmail(static::getContainer(), 'wear-fitting@test.local');
        [$item] = $this->items($user, ['Платье'], ['900.00']);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/account/wardrobe/wear');
        $client->submit($crawler->selectButton('Без фото — выбрать вещи вручную')->form());
        $crawler = $client->followRedirect();
        $client->submit($crawler->selectButton('Подтвердить образ')->form([
            'items' => [(string) $item->getId()],
            'type' => WardrobeWearEvent::TYPE_FITTING,
        ]));
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('body', 'Примерка');
        self::assertStringNotContainsString('900,00 ₽ / носку', $crawler->html());

        $event = static::getContainer()->get('doctrine.orm.entity_manager')->getRepository(WardrobeWearEvent::class)
            ->findOneBy(['profileSubject' => $user], ['id' => 'DESC']);
        $crawler = $client->request('GET', '/account/wardrobe/wear/'.$event->getId());
        self::assertSelectorExists('select[name="type"] option[value="fitting"][selected]');
        $client->submit($crawler->selectButton('Подтвердить образ')->form([
            'items' => [(string) $item->getId()],
        ]));
        $crawler = $client->followRedirect();
        self::assertStringNotContainsString('900,00 ₽ / носку', $crawler->html());
    }

    public function testParentRecordsObservedWearForChildAndChildCannotUseParentProfile(): void
    {
        $client = static::createClient();
        $parent = UserFactory::withEmail(static::getContainer(), 'wear-parent@test.local');
        $child = static::getContainer()->get(FamilyService::class)->createChild($parent, 'Wear child');
        [$item] = $this->items($child, ['Худи']);
        $client->loginUser($parent);
        $crawler = $client->request('GET', '/account/wardrobe/wear?member='.$child->getId());
        $client->submit($crawler->selectButton('Без фото — выбрать вещи вручную')->form());
        $crawler = $client->followRedirect();
        $client->submit($crawler->selectButton('Подтвердить образ')->form(['items' => [(string) $item->getId()], 'type' => 'worn']));
        self::assertResponseRedirects('/account/wardrobe/wear?member='.$child->getId());

        $event = static::getContainer()->get('doctrine.orm.entity_manager')->getRepository(WardrobeWearEvent::class)
            ->findOneBy(['profileSubject' => $child], ['id' => 'DESC']);
        self::assertSame('parent_observed', $event->getSignalSource());
        self::assertSame($parent->getId(), $event->getActor()->getId());

        $client->loginUser($child);
        $client->request('GET', '/account/wardrobe/wear?member='.$parent->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testPhotoRecognitionCreatesReviewButNotWearUntilConfirmation(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $user = UserFactory::withEmail(static::getContainer(), 'wear-photo@test.local');
        [$item] = $this->items($user, ['Куртка']);
        $client->loginUser($user);
        $recognition = $this->createMock(WardrobeWearRecognitionService::class);
        $itemId = $item->getId();
        $recognition->expects(self::once())->method('candidates')->willReturnCallback(static function () use ($itemId): array {
            $managed = static::getContainer()->get('doctrine.orm.entity_manager')->find(WardrobeItem::class, $itemId);
            return [['item' => $managed, 'confidence' => 'high']];
        });
        static::getContainer()->set(WardrobeWearRecognitionService::class, $recognition);
        $path = tempnam(sys_get_temp_dir(), 'wear_test_');
        $image = imagecreatetruecolor(20, 20);
        imagejpeg($image, $path);
        imagedestroy($image);
        $crawler = $client->request('GET', '/account/wardrobe/wear');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/account/wardrobe/wear', ['_token' => $token, 'photoConsent' => '1'], [
            'photo' => new UploadedFile($path, 'outfit.jpg', 'image/jpeg', null, true),
        ]);
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertSelectorExists('input[name="items[]"][value="'.$item->getId().'"]:checked');
        $mediaUrl = $crawler->filter('img[alt="Загруженный образ"]')->attr('src');
        $client->request('GET', $mediaUrl);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $event = static::getContainer()->get('doctrine.orm.entity_manager')->getRepository(WardrobeWearEvent::class)
            ->findOneBy(['profileSubject' => $user], ['id' => 'DESC']);
        self::assertSame(WardrobeWearEvent::STATUS_REVIEW, $event->getStatus());

        $foreign = UserFactory::withEmail(static::getContainer(), 'wear-photo-foreign@test.local');
        $client->loginUser($foreign);
        $client->request('GET', $mediaUrl);
        self::assertResponseStatusCodeSame(404);
    }

    /** @param string[] $names @param string[] $prices @return WardrobeItem[] */
    private function items(User $owner, array $names, array $prices = []): array
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $next = static::getContainer()->get(\App\Repository\WardrobeItemRepository::class)->nextItemNo($owner);
        $items = [];
        foreach ($names as $index => $name) {
            $item = (new WardrobeItem())->setUser($owner)->setItemNo($next + $index)->setName($name)->setCategory($name);
            if (isset($prices[$index])) {
                $item->setPrice($prices[$index]);
            }
            $em->persist($item);
            $items[] = $item;
        }
        $em->flush();
        return $items;
    }
}
