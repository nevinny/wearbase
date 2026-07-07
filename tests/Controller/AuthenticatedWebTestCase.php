<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Brand;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * База для функциональных тестов, которым нужен залогиненный пользователь.
 *
 * Хелперы персистят пользователя в тест-БД и выполняют loginUser() на firewall'е `main`.
 * Пользователь реально существует в БД → refreshUser() на следующем запросе не падает.
 *
 * Порядок: сначала static::createClient() (бутит kernel), затем login-хелпер —
 * static::getContainer() отдаёт контейнер того же kernel с доступом к приватным сервисам.
 */
abstract class AuthenticatedWebTestCase extends DatabaseDependentWebTestCase
{
    protected function loginAsCustomer(KernelBrowser $client): User
    {
        $user = UserFactory::customer(static::getContainer());
        $client->loginUser($user);

        return $user;
    }

    protected function loginAsBrandOwner(KernelBrowser $client): User
    {
        $user = UserFactory::brandOwner(static::getContainer());
        $client->loginUser($user);

        return $user;
    }

    /**
     * Логинит бренд-владельца, у которого есть бренд и связь BrandUser.
     * Для страниц /brand LK, где контроллер резолвит активный бренд по пользователю.
     *
     * @return array{0: User, 1: Brand}
     */
    protected function loginAsBrandOwnerWithBrand(KernelBrowser $client): array
    {
        [$user, $brand] = UserFactory::brandOwnerWithBrand(static::getContainer());
        $client->loginUser($user);

        return [$user, $brand];
    }
}
