<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

interface WardrobeWeatherContextProviderInterface
{
    public function current(): ?string;
}
