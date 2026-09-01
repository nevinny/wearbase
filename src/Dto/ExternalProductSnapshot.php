<?php

declare(strict_types=1);

namespace App\Dto;

use App\ValueObject\ExternalProductUrl;
use App\ValueObject\MoneyAmount;

final readonly class ExternalProductSnapshot
{
    public string $sourceUrl;
    public ?string $estimatedPrice;

    public function __construct(
        public string $provider,
        string|ExternalProductUrl $sourceUrl,
        ?string $estimatedPrice = null,
        public ?string $externalId = null,
        public ?string $title = null,
        public string $currency = 'RUB',
    ) {
        $this->sourceUrl = ($sourceUrl instanceof ExternalProductUrl ? $sourceUrl : ExternalProductUrl::fromString($sourceUrl))->toString();
        $this->estimatedPrice = $estimatedPrice === null ? null : MoneyAmount::normalize($estimatedPrice);
        if (!preg_match('/^[a-z0-9_-]{2,32}$/', $provider)
            || ($externalId !== null && mb_strlen($externalId) > 128)
            || ($title !== null && mb_strlen($title) > 300)
            || !preg_match('/^[A-Z]{3}$/', $currency)
        ) {
            throw new \InvalidArgumentException('Некорректный normalized product snapshot');
        }
    }
}
