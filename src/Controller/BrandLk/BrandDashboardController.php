<?php

declare(strict_types=1);

namespace App\Controller\BrandLk;

use App\Entity\Order;
use App\Entity\Subscription;
use App\Repository\BrandUserRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/brand', name: 'brand_')]
class BrandDashboardController extends AbstractController
{
    public function __construct(
        private readonly BrandUserRepository $brandUserRepository,
        private readonly SubscriptionRepository $subscriptionRepository,
    ) {}

    #[Route('/dashboard', name: 'dashboard')]
    public function dashboard(
        OrderRepository   $orderRepository,
        ProductRepository $productRepository,
    ): Response {
        $brand = $this->getActiveBrand();

        $newOrders     = $orderRepository->findBy(['brand' => $brand, 'status' => Order::STATUS_NEW]);
        $recentOrders  = $orderRepository->findBy(['brand' => $brand], ['createdAt' => 'DESC'], 10);
        $lowStockItems = $productRepository->findLowStockVariants($brand);

        return $this->render('brand_lk/dashboard.html.twig', [
            'brand'         => $brand,
            'new_orders'    => $newOrders,
            'recent_orders' => $recentOrders,
            'low_stock'     => $lowStockItems,
        ]);
    }

    /**
     * Возвращает активный бренд текущего пользователя.
     * Если менеджер управляет несколькими брендами — берём первый
     * (впоследствии можно добавить переключатель).
     */
    protected function getActiveBrand(): \App\Entity\Brand
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $brandUser = $this->brandUserRepository->findOneBy(['user' => $user]);

        if ($brandUser === null) {
            throw $this->createAccessDeniedException('Вы не привязаны ни к одному бренду.');
        }

        return $brandUser->getBrand();
    }

    protected function getActiveSubscription(): ?Subscription
    {
        return $this->subscriptionRepository->findActiveByBrand($this->getActiveBrand());
    }
}
