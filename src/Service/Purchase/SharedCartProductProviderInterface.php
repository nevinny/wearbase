<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Dto\ExternalProductSnapshot;
use App\ValueObject\ExternalProductUrl;

interface SharedCartProductProviderInterface extends ExternalProductProviderInterface
{
    /** @return ExternalProductSnapshot[] */
    public function importSharedCart(ExternalProductUrl $url): array;
}
