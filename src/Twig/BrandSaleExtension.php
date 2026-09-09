<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Brand;
use App\Repository\SellerLegalEntityRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Предикат «бренд может продавать» — тот же, что в BrandDashboardController::dashboard()
 * (payment_ready) и PaymentService::createOrderPayment(): действующее юр.лицо бренда
 * должно иметь основной счёт, реально готовый принимать онлайн-оплату (see
 * SellerLegalEntity::getReadyPrimaryAccount()).
 *
 * Twig: {{ brand_can_sell(brand) }}
 *
 * Кеширует результат в памяти по brand_id (как TranslationExtension) — сервис живёт
 * ровно на время запроса (или один прогон консольной команды).
 */
class BrandSaleExtension extends AbstractExtension
{
    /** @var array<int, bool> */
    private array $cache = [];

    public function __construct(
        private readonly SellerLegalEntityRepository $legalEntities,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('brand_can_sell', $this->canSell(...)),
        ];
    }

    public function canSell(Brand $brand): bool
    {
        $id = $brand->getId();
        if ($id === null) {
            return false;
        }

        if (!array_key_exists($id, $this->cache)) {
            $this->cache[$id] = $this->legalEntities->findActiveForBrand($brand)?->getReadyPrimaryAccount() !== null;
        }

        return $this->cache[$id];
    }
}
