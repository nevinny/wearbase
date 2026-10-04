<?php

declare(strict_types=1);

namespace App\Tests\Service\Telegram;

use App\Entity\TelegramDialogState;
use App\Entity\User;
use App\Entity\Wardrobe;
use App\Entity\WardrobeCategory;
use App\Entity\WardrobeItem;
use App\Notification\TelegramNotifier;
use App\Repository\TelegramDialogStateRepository;
use App\Repository\WardrobeCategoryRepository;
use App\Repository\WardrobeItemRepository;
use App\Service\Telegram\TelegramFileFetcher;
use App\Service\Telegram\WardrobeDialogService;
use App\Service\Telegram\WardrobeTemplate;
use App\Service\Wardrobe\WardrobeAiService;
use App\Service\Wardrobe\WardrobeManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WardrobeDialogServiceTest extends TestCase
{
    #[DataProvider('categories')]
    public function testSaveKeepsRecognizedAttributesAndResolvesCategory(string $label, ?string $expectedCode, string $season, ?string $expectedSeason): void
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);
        $state = (new TelegramDialogState('123'))->setDraft([
            'category' => $label, 'name' => 'Вещь из Telegram', 'season' => $season,
            'colorName' => 'синий', 'materialText' => 'шерсть', 'size' => '52',
        ]);
        $states = $this->createStub(TelegramDialogStateRepository::class);
        $states->method('findByChatId')->willReturn($state);
        $items = $this->createStub(WardrobeItemRepository::class);
        $items->method('nextItemNo')->willReturn(1);
        $items->method('getStats')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(static function (object $record) use ($user, $expectedCode, $expectedSeason, $label): void {
            self::assertInstanceOf(WardrobeItem::class, $record);
            self::assertSame($user, $record->getUser());
            self::assertSame($expectedCode, $record->getCategoryRef()?->getCode());
            self::assertSame($expectedCode === null ? $label : 'Шапка', $record->getCategory());
            self::assertSame($expectedSeason, $record->getSeason());
            self::assertSame('синий', $record->getColorName());
            self::assertSame('шерсть', $record->getMaterialText());
            self::assertSame('52', $record->getSize());
            self::assertSame(WardrobeItem::SOURCE_TELEGRAM, $record->getSource());
        });
        $em->expects(self::once())->method('remove')->with($state);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);
        $manager = $this->createStub(WardrobeManager::class);
        $manager->method('getOrCreateDefault')->willReturn((new Wardrobe())->setOwner($user));
        $categories = $this->getMockBuilder(WardrobeCategoryRepository::class)->disableOriginalConstructor()->onlyMethods(['findActiveTree'])->getMock();
        $categories->method('findActiveTree')->willReturn([(new WardrobeCategory())->setCode('hat')->setName('Шапка')]);
        $http = $this->createStub(HttpClientInterface::class);
        $service = new WardrobeDialogService(
            $registry, $items, $states, new WardrobeTemplate(),
            new TelegramFileFetcher($http, '', new NullLogger()),
            new TelegramNotifier($http, '', new NullLogger()),
            $this->createStub(WardrobeAiService::class), $manager, new NullLogger(), $categories,
        );

        self::assertTrue($service->handleCallback($user, 'wa:save', '123'));
    }

    public static function categories(): array
    {
        return [
            ['Шапка', 'hat', 'winter', 'winter'],
            ['Шапки', 'hat', 'winter', 'winter'],
            ['Авторский предмет', null, 'invalid', null],
        ];
    }
}
