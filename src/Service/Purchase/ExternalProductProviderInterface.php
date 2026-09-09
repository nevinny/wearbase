<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Dto\ExternalProductSnapshot;
use App\ValueObject\ExternalProductUrl;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.external_product_provider')]
interface ExternalProductProviderInterface
{
    public function code(): string;

    public function supports(ExternalProductUrl $url): bool;

    public function importDirect(ExternalProductUrl $url, ?string $estimatedPrice = null): ExternalProductSnapshot;
}
