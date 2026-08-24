<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

final class NullWardrobeWeatherContextProvider implements WardrobeWeatherContextProviderInterface
{
    public function current(): ?string { return null; }
}
