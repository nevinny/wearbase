<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\WardrobeItem;
use App\Service\Wardrobe\WardrobeOutfitService;

class WardrobeOutfitControllerTest extends AuthenticatedWebTestCase
{
    public function testOutfitPageRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/wardrobe/outfits');

        self::assertResponseRedirects();
    }

    public function testCustomerCanOpenOutfitPage(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);
        $client->request('GET', '/account/wardrobe/outfits');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'AI-стилист');
        self::assertSelectorExists('form textarea[name="prompt"]');
    }

    public function testInvalidCsrfDoesNotCallLlm(): void
    {
        $client = static::createClient();
        $this->loginAsCustomer($client);
        $client->request('POST', '/account/wardrobe/outfits', ['_token' => 'wrong', 'prompt' => 'В офис']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Недействительный токен');
    }

    public function testValidPostRendersSuggestedOutfit(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $user = $this->loginAsCustomer($client);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $shirt = (new WardrobeItem())->setUser($user)->setItemNo(9801)->setName('Белая рубашка')->setCategory('Рубашки');
        $trousers = (new WardrobeItem())->setUser($user)->setItemNo(9802)->setName('Синие брюки')->setCategory('Брюки');
        $em->persist($shirt);
        $em->persist($trousers);
        $em->flush();

        $mock = $this->createMock(WardrobeOutfitService::class);
        $mock->expects(self::once())
            ->method('suggest')
            ->with(
                self::callback(static fn ($actor): bool => $actor->getId() === $user->getId()),
                self::callback(static function (array $items): bool {
                    $names = array_map(static fn (WardrobeItem $item): ?string => $item->getName(), $items);

                    return in_array('Белая рубашка', $names, true) && in_array('Синие брюки', $names, true);
                }),
                'В офис',
                '',
            )
            ->willReturn([[
                'title' => 'Спокойный офис',
                'explanation' => 'Базовые цвета сочетаются.',
                'items' => [$shirt, $trousers],
            ]]);
        static::getContainer()->set(WardrobeOutfitService::class, $mock);

        $crawler = $client->request('GET', '/account/wardrobe/outfits');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $crawler = $client->request('POST', '/account/wardrobe/outfits', ['_token' => $token, 'prompt' => 'В офис']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Спокойный офис');
        self::assertSelectorTextContains('body', 'Белая рубашка');
        self::assertSelectorTextContains('body', 'Синие брюки');

        $client->submit($crawler->selectButton('❤️ Нравится')->form());
        self::assertResponseRedirects('/account/wardrobe/outfits');
        $saved = $em->getRepository(\App\Entity\WardrobeOutfit::class)->findOneBy(['user' => $user], ['id' => 'DESC']);
        self::assertSame('like', $saved?->getReaction());
    }
}
