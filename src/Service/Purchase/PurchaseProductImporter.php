<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Dto\ExternalProductSnapshot;
use App\ValueObject\ExternalProductUrl;

final class PurchaseProductImporter
{
    private const MAX_ITEMS = 10;

    public function __construct(private readonly ExternalProductProviderRegistry $providers) {}

    /** @param string[] $additionalUrls @return ExternalProductSnapshot[] */
    public function importLinks(string $productUrl, array $additionalUrls, ?string $estimatedPrice): array
    {
        if (count($additionalUrls) >= self::MAX_ITEMS) {
            throw new \InvalidArgumentException('В одном запросе можно до 10 вещей');
        }
        $snapshots = [];
        foreach ([$productUrl, ...$additionalUrls] as $index => $rawUrl) {
            $url = ExternalProductUrl::fromString($rawUrl);
            $snapshot = $this->providers->direct($url)->importDirect($url, $index === 0 ? $estimatedPrice : null);
            $snapshots[$snapshot->sourceUrl] ??= $snapshot;
        }

        return array_values($snapshots);
    }

    /** @return ExternalProductSnapshot[] */
    public function importSharedCart(string $url): array
    {
        $normalized = ExternalProductUrl::fromString($url);
        $snapshots = $this->providers->sharedCart($normalized)->importSharedCart($normalized);
        if ($snapshots === [] || count($snapshots) > self::MAX_ITEMS) {
            throw new \DomainException('Корзина должна содержать от 1 до 10 товаров');
        }

        return array_values($snapshots);
    }

    public function isSharedCartEnabled(): bool
    {
        return $this->providers->isSharedCartEnabled();
    }
}
