<?php

declare(strict_types=1);

namespace App\Tests\Service\Purchase;

use App\Dto\ExternalProductSnapshot;
use App\Service\Purchase\ExternalProductProviderInterface;
use App\Service\Purchase\ExternalProductProviderRegistry;
use App\Service\Purchase\ManualProductProvider;
use App\Service\Purchase\PurchaseProductImporter;
use App\Service\Purchase\SharedCartProductProviderInterface;
use App\ValueObject\ExternalProductUrl;
use PHPUnit\Framework\TestCase;

final class ExternalProductProviderRegistryTest extends TestCase
{
    public function testKnownProviderWinsAndUnknownUrlUsesManualWithoutCallingKnownImporter(): void
    {
        $known = $this->createMock(ExternalProductProviderInterface::class);
        $known->method('code')->willReturn('known');
        $known->method('supports')->willReturnCallback(
            static fn (ExternalProductUrl $url): bool => $url->host() === 'known.example.test',
        );
        $known->expects(self::once())->method('importDirect')->willReturn(
            new ExternalProductSnapshot('known', 'https://known.example.test/item/1', '1200'),
        );
        $manual = new ManualProductProvider();
        $importer = new PurchaseProductImporter(new ExternalProductProviderRegistry([$manual, $known], false));

        $knownResult = $importer->importLinks('https://known.example.test/item/1', [], '1200');
        $unknownResult = $importer->importLinks('https://unknown.example.test/item/2', [], null);

        self::assertSame('known', $knownResult[0]->provider);
        self::assertSame('manual', $unknownResult[0]->provider);
        self::assertSame('https://unknown.example.test/item/2', $unknownResult[0]->sourceUrl);
    }

    public function testMultiLinkFallbackNormalizesDeduplicatesAndKeepsPriceOnFirstItem(): void
    {
        $importer = new PurchaseProductImporter(new ExternalProductProviderRegistry([new ManualProductProvider()], false));

        $snapshots = $importer->importLinks('https://SHOP.example.test/item/1#share', [
            'https://shop.example.test/item/1',
            'https://another.example.test/item/2',
        ], '1299.90');

        self::assertCount(2, $snapshots);
        self::assertSame('https://shop.example.test/item/1', $snapshots[0]->sourceUrl);
        self::assertSame('1299.90', $snapshots[0]->estimatedPrice);
        self::assertNull($snapshots[1]->estimatedPrice);
    }

    public function testSharedCartIsFeatureFlaggedAndRequiresExplicitCapability(): void
    {
        $url = 'https://cart.example.test/shared/1';
        $disabled = new PurchaseProductImporter(new ExternalProductProviderRegistry([new ManualProductProvider()], false));
        try {
            $disabled->importSharedCart($url);
            self::fail('Disabled shared cart must fail closed');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('выключен', $exception->getMessage());
        }

        $manualOnly = new PurchaseProductImporter(new ExternalProductProviderRegistry([new ManualProductProvider()], true));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('отдельные ссылки');
        $manualOnly->importSharedCart($url);
    }

    public function testSharedCartCapabilityReturnsNormalizedSnapshotsWithoutChangingDomainEntities(): void
    {
        $provider = new class implements SharedCartProductProviderInterface {
            public function code(): string { return 'fixture'; }
            public function supports(ExternalProductUrl $url): bool { return $url->host() === 'cart.example.test'; }
            public function importDirect(ExternalProductUrl $url, ?string $estimatedPrice = null): ExternalProductSnapshot
            {
                return new ExternalProductSnapshot($this->code(), $url, $estimatedPrice);
            }
            public function importSharedCart(ExternalProductUrl $url): array
            {
                return [
                    new ExternalProductSnapshot($this->code(), 'https://shop-a.example.test/1', '500', 'a-1', 'Футболка'),
                    new ExternalProductSnapshot($this->code(), 'https://shop-a.example.test/2', '700', 'a-2', 'Брюки'),
                ];
            }
        };
        $importer = new PurchaseProductImporter(new ExternalProductProviderRegistry([new ManualProductProvider(), $provider], true));

        $snapshots = $importer->importSharedCart('https://cart.example.test/shared/1');

        self::assertSame(['a-1', 'a-2'], array_column($snapshots, 'externalId'));
        self::assertSame(['500.00', '700.00'], array_column($snapshots, 'estimatedPrice'));
    }
}
