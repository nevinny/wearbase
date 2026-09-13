<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Entity\Wardrobe;
use App\Entity\WardrobeConsent;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeOutfit;
use App\Tests\Controller\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Агент-API ночного пакетного конвейера образов (/api/v1/wardrobe/daily/*).
 * Аутентификация — X-Agent-Token (см. WardrobeDailyController::authorize), без HMAC.
 */
class WardrobeDailyControllerTest extends WebTestCase
{
    private const TOKEN = 'test-agent-token';

    public function testOutfitsRequiresToken(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/wardrobe/daily/outfits', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testAcceptsValidOutfitForOwnedWardrobe(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, $wardrobe, $items] = $this->makeWardrobeWithItems($em, 'daily-accept', 2);

        $body = json_encode([
            'wardrobe_id' => $wardrobe->getId(),
            'occasion' => WardrobeOutfit::OCCASION_WORK,
            'request' => WardrobeOutfit::DAILY_OCCASIONS[WardrobeOutfit::OCCASION_WORK],
            'outfits' => [
                ['title' => 'Строгий образ', 'explanation' => 'Рубашка и брюки', 'item_ids' => [$items[0]->getId(), $items[1]->getId()]],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->postOutfits($client, $body);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['created']);
        $this->assertSame([], $data['rejected']);

        $em->clear();
        $outfits = $em->getRepository(WardrobeOutfit::class)->findBy(['wardrobeOwner' => $owner->getId()]);
        $this->assertCount(1, $outfits);
        $this->assertSame(WardrobeOutfit::OCCASION_WORK, $outfits[0]->getOccasion());
        $this->assertNull($outfits[0]->getDeletedAt());
        $this->assertSame(
            [$items[0]->getId(), $items[1]->getId()],
            array_column($outfits[0]->getItems(), 'id'),
        );
    }

    public function testSkipsWardrobeWithoutPersonalizationConsent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, $wardrobe, $items] = $this->makeWardrobeWithItems($em, 'daily-no-consent', 2, withConsent: false);

        $body = json_encode([
            'wardrobe_id' => $wardrobe->getId(),
            'occasion' => WardrobeOutfit::OCCASION_WORK,
            'request' => 'Образ на работу',
            'outfits' => [['title' => 'Образ', 'explanation' => '', 'item_ids' => [$items[0]->getId(), $items[1]->getId()]]],
        ], JSON_THROW_ON_ERROR);

        $this->postOutfits($client, $body);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('consent_denied', $data['status']);
        $this->assertSame(0, $data['created']);

        $em->clear();
        $outfits = $em->getRepository(WardrobeOutfit::class)->findBy(['wardrobeOwner' => $owner->getId()]);
        $this->assertCount(0, $outfits);
    }

    public function testRejectsOutfitContainingForeignOrUnknownItemId(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$ownerA, $wardrobeA, $itemsA] = $this->makeWardrobeWithItems($em, 'daily-owner-a', 2);
        [, , $itemsB] = $this->makeWardrobeWithItems($em, 'daily-owner-b', 1);

        $body = json_encode([
            'wardrobe_id' => $wardrobeA->getId(),
            'occasion' => WardrobeOutfit::OCCASION_WALK,
            'request' => 'Образ на прогулку',
            'outfits' => [
                ['title' => 'Валидный', 'explanation' => '', 'item_ids' => [$itemsA[0]->getId(), $itemsA[1]->getId()]],
                ['title' => 'Чужая вещь', 'explanation' => '', 'item_ids' => [$itemsA[0]->getId(), $itemsB[0]->getId()]],
                ['title' => 'Несуществующая вещь', 'explanation' => '', 'item_ids' => [$itemsA[0]->getId(), 9999999]],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->postOutfits($client, $body);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['created']);
        $this->assertCount(2, $data['rejected']);
        $this->assertSame('unknown_item_id', $data['rejected'][0]['reason']);
        $this->assertSame('unknown_item_id', $data['rejected'][1]['reason']);

        $em->clear();
        $outfits = $em->getRepository(WardrobeOutfit::class)->findBy(['wardrobeOwner' => $ownerA->getId()]);
        $this->assertCount(1, $outfits);
    }

    public function testRerunSameDayAndOccasionReplacesInsteadOfDuplicating(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, $wardrobe, $items] = $this->makeWardrobeWithItems($em, 'daily-idempotent', 2);

        $firstBody = json_encode([
            'wardrobe_id' => $wardrobe->getId(),
            'occasion' => WardrobeOutfit::OCCASION_MEETING,
            'request' => 'Образ на встречу',
            'outfits' => [['title' => 'Первый прогон', 'explanation' => '', 'item_ids' => [$items[0]->getId(), $items[1]->getId()]]],
        ], JSON_THROW_ON_ERROR);
        $this->postOutfits($client, $firstBody);
        $this->assertResponseIsSuccessful();

        $secondBody = json_encode([
            'wardrobe_id' => $wardrobe->getId(),
            'occasion' => WardrobeOutfit::OCCASION_MEETING,
            'request' => 'Образ на встречу',
            'outfits' => [['title' => 'Повторный прогон', 'explanation' => '', 'item_ids' => [$items[0]->getId(), $items[1]->getId()]]],
        ], JSON_THROW_ON_ERROR);
        $this->postOutfits($client, $secondBody);
        $this->assertResponseIsSuccessful();

        $em->clear();
        $all = $em->getRepository(WardrobeOutfit::class)->findBy(['wardrobeOwner' => $owner->getId()]);
        // Оба прогона физически в БД (soft-delete, не DELETE), но активен только последний.
        $this->assertCount(2, $all);
        $active = array_values(array_filter($all, static fn (WardrobeOutfit $o): bool => $o->getDeletedAt() === null));
        $this->assertCount(1, $active);
        $this->assertSame('Повторный прогон', $active[0]->getTitle());
    }

    /**
     * Регрессия: catalog() раньше отдавал вещи через широкий findActiveForUser() (только
     * deletedAt/archive/given_away), минуя строгий фильтр WardrobeStylistContextBuilder
     * (ITEM_ACTIVE + WEAR_ACTIVE + CLEANLINESS_CLEAN) — ночной батч предлагал грязные вещи.
     */
    public function testCatalogExcludesDirtyItemsAndReturnsRotationAndPreferenceContext(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, $wardrobe, $items] = $this->makeWardrobeWithItems($em, 'daily-catalog', 2);
        $dirty = (new WardrobeItem())
            ->setUser($owner)
            ->setWardrobe($wardrobe)
            ->setItemNo(99)
            ->setCategory('Рубашки')
            ->setColorName('белый')
            ->setCleanlinessStatus(WardrobeItem::CLEANLINESS_DIRTY);
        $em->persist($dirty);
        $em->flush();

        $client->request('GET', '/api/v1/wardrobe/daily/catalog', [], [], ['HTTP_X_AGENT_TOKEN' => self::TOKEN]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        // БД теста общая на весь прогон phpunit (var/test.db не сбрасывается между методами) —
        // фильтруем ответ до "своего" гардероба, не полагаемся на count($data['wardrobes']).
        $mine = array_values(array_filter(
            $data['wardrobes'],
            static fn (array $w): bool => (int) $w['wardrobe_id'] === $wardrobe->getId(),
        ));
        $this->assertCount(1, $mine);
        $ids = array_column($mine[0]['items'], 'id');
        $this->assertCount(2, $ids);
        $this->assertNotContains($dirty->getId(), $ids);
        // findActiveForUser() сортирует по itemNo DESC — $items[1] (itemNo=2) идёт первым.
        $this->assertSame([$items[1]->getId(), $items[0]->getId()], $ids);
        $this->assertSame('fresh', $mine[0]['items'][0]['rotation']);
        $this->assertArrayHasKey('preference_context', $mine[0]);
    }

    /** @return array{0:User,1:Wardrobe,2:WardrobeItem[]} */
    private function makeWardrobeWithItems(EntityManagerInterface $em, string $emailPrefix, int $itemCount, bool $withConsent = true): array
    {
        $owner = UserFactory::withEmail(static::getContainer(), $emailPrefix . '-' . uniqid('', true) . '@test.local');
        $wardrobe = (new Wardrobe())->setOwner($owner);
        $em->persist($wardrobe);

        if ($withConsent) {
            $consent = new WardrobeConsent($owner, $owner);
            $consent->grantPersonalization($owner);
            $em->persist($consent);
        }

        $items = [];
        for ($i = 1; $i <= $itemCount; $i++) {
            $item = (new WardrobeItem())
                ->setUser($owner)
                ->setWardrobe($wardrobe)
                ->setItemNo($i)
                ->setCategory('Рубашки')
                ->setColorName('белый');
            $em->persist($item);
            $items[] = $item;
        }
        $em->flush();

        return [$owner, $wardrobe, $items];
    }

    private function postOutfits(KernelBrowser $client, string $body): void
    {
        $client->request(
            'POST',
            '/api/v1/wardrobe/daily/outfits',
            [],
            [],
            ['HTTP_X_AGENT_TOKEN' => self::TOKEN, 'CONTENT_TYPE' => 'application/json'],
            $body,
        );
    }
}
