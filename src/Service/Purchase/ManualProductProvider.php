<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Dto\ExternalProductSnapshot;
use App\ValueObject\ExternalProductUrl;

final class ManualProductProvider implements ExternalProductProviderInterface
{
    public function code(): string
    {
        return 'manual';
    }

    public function supports(ExternalProductUrl $url): bool
    {
        return true;
    }

    public function importDirect(ExternalProductUrl $url, ?string $estimatedPrice = null): ExternalProductSnapshot
    {
        return new ExternalProductSnapshot($this->code(), $url, $estimatedPrice);
    }
}
