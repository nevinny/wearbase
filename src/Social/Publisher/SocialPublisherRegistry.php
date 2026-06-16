<?php

declare(strict_types=1);

namespace App\Social\Publisher;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Реестр публикаторов: резолвит реализацию по коду площадки (tg|vk|ig).
 * Незарегистрированная площадка → исключение.
 */
class SocialPublisherRegistry
{
    /** @var array<string, SocialPublisherInterface> */
    private array $byPlatform = [];

    /**
     * @param iterable<SocialPublisherInterface> $publishers
     */
    public function __construct(
        #[TaggedIterator('app.social_publisher')] iterable $publishers,
    ) {
        foreach ($publishers as $publisher) {
            $this->byPlatform[$publisher->platform()] = $publisher;
        }
    }

    public function has(string $platform): bool
    {
        return isset($this->byPlatform[$platform]);
    }

    public function get(string $platform): SocialPublisherInterface
    {
        return $this->byPlatform[$platform]
            ?? throw new \RuntimeException(sprintf('Публикатор для площадки «%s» не зарегистрирован.', $platform));
    }
}
