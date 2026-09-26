<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\ValueObject\ExternalProductUrl;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ExternalProductProviderRegistry
{
    /** @var ExternalProductProviderInterface[] */
    private array $providers;

    public function __construct(
        #[AutowireIterator('app.external_product_provider')]
        iterable $providers,
        #[Autowire('%env(bool:PURCHASE_SHARED_CART_ENABLED)%')]
        private readonly bool $sharedCartEnabled,
    ) {
        $this->providers = iterator_to_array($providers, false);
    }

    public function direct(ExternalProductUrl $url): ExternalProductProviderInterface
    {
        foreach ($this->providers as $provider) {
            if (!$provider instanceof ManualProductProvider && $provider->supports($url)) {
                return $provider;
            }
        }
        foreach ($this->providers as $provider) {
            if ($provider instanceof ManualProductProvider) {
                return $provider;
            }
        }

        throw new \LogicException('ManualProductProvider must be registered');
    }

    public function sharedCart(ExternalProductUrl $url): SharedCartProductProviderInterface
    {
        if (!$this->sharedCartEnabled) {
            throw new \DomainException('Автоимпорт общей корзины пока выключен. Добавьте отдельные ссылки ниже.');
        }
        foreach ($this->providers as $provider) {
            if ($provider instanceof SharedCartProductProviderInterface && $provider->supports($url)) {
                return $provider;
            }
        }

        throw new \DomainException('Этот магазин не поддерживает автоимпорт корзины. Добавьте отдельные ссылки ниже.');
    }

    public function isSharedCartEnabled(): bool
    {
        return $this->sharedCartEnabled;
    }
}
