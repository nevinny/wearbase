<?php

declare(strict_types=1);

namespace App\Controller\BrandLk;

use App\Repository\SubscriptionRepository;
use App\Repository\TariffRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/brand/settings', name: 'brand_setting')]
class BrandSettingsController extends BrandDashboardController
{
    #[Route('', name: '')]
    public function index(
        TariffRepository $tariffRepo,
        SubscriptionRepository $subRepo,
    ): Response {
        $brand = $this->getActiveBrand();
        $activeSub = $subRepo->findActiveByBrand($brand);
        $tariffs = $tariffRepo->findActive();

        return $this->render('brand_lk/settings.html.twig', [
            'brand'     => $brand,
            'subscription' => $activeSub,
            'tariffs'   => $tariffs,
        ]);
    }
}
