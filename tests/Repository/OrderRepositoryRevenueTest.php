<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Brand;
use App\Entity\Order;
use App\Entity\User;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class OrderRepositoryRevenueTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private OrderRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(OrderRepository::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    private function brand(string $slug): Brand
    {
        $brand = new Brand();
        $brand->setTitle($slug);
        $brand->setSlug($slug . '-' . uniqid());
        $this->em->persist($brand);
        return $brand;
    }

    private function customer(): User
    {
        $user = new User();
        $user->setEmail('cust-' . uniqid() . '@example.com');
        $user->setPassword('x');
        $this->em->persist($user);
        return $user;
    }

    private function order(Brand $brand, User $user, string $amount, string $paymentStatus): void
    {
        $order = new Order();
        $order->setOrderNumber('ON-' . uniqid());
        $order->setBrand($brand);
        $order->setCustomer($user);
        $order->setTotalAmount($amount);
        $order->setPaymentStatus($paymentStatus);
        $this->em->persist($order);
    }

    public function testSumPaidRevenueCountsOnlyPaidOrdersOfBrand(): void
    {
        $user = $this->customer();
        $brandA = $this->brand('brand-a');
        $brandB = $this->brand('brand-b');
        $brandEmpty = $this->brand('brand-empty');

        $this->order($brandA, $user, '100.00', Order::PAYMENT_PAID);
        $this->order($brandA, $user, '200.00', Order::PAYMENT_PAID);
        $this->order($brandA, $user, '50.00', Order::PAYMENT_PENDING); // не учитывается
        $this->order($brandB, $user, '999.00', Order::PAYMENT_PAID);   // другой бренд
        $this->em->flush();

        $this->assertSame(300.0, (float) $this->repo->sumPaidRevenue($brandA));
        $this->assertSame(0.0, (float) $this->repo->sumPaidRevenue($brandEmpty));
    }

    public function testCountByBrand(): void
    {
        $user = $this->customer();
        $brandA = $this->brand('brand-a');

        $this->order($brandA, $user, '100.00', Order::PAYMENT_PAID);
        $this->order($brandA, $user, '50.00', Order::PAYMENT_PENDING);
        $this->em->flush();

        $this->assertSame(2, $this->repo->countByBrand($brandA));
    }
}
