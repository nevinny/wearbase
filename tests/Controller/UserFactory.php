<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Brand;
use App\Entity\BrandUser;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Персистит тестовых пользователей в тест-БД (SQLite, см. tests/bootstrap.php).
 *
 * loginUser() требует пользователя с идентификатором: на следующем запросе
 * EntityUserProvider::refreshUser() перечитывает его из БД по email. Раньше фабрика
 * отдавала неперсистентные заглушки без id → refreshUser падал «cannot refresh a user
 * without identifier» → HTTP 500 на всех authenticated-тестах. Теперь пользователи
 * реально сохраняются с хешированным паролем — как в проде (тот же провайдер).
 *
 * Все методы идемпотентны (find-or-create по email), пароль у всех — self::PASSWORD.
 * Контейнер брать из static::getContainer() тест-кейса (даёт доступ к приватным сервисам).
 */
final class UserFactory
{
    public const PASSWORD = 'test-password';

    // Emails в отдельном namespace `harness-*`, чтобы не пересекаться с email'ами,
    // которые захардкожены в KernelTestCase-тестах (напр. owner@test.local в BrandClaimGrantIntegrationTest):
    // функциональные тесты коммитят своих пользователей, integration-тесты работают в rollback-транзакции,
    // и одинаковый email дал бы UNIQUE-конфликт на client.email.
    public static function customer(ContainerInterface $c): User
    {
        return self::findOrCreate($c, 'harness-customer@test.local', ['ROLE_CUSTOMER']);
    }

    public static function brandManager(ContainerInterface $c): User
    {
        return self::findOrCreate($c, 'harness-manager@test.local', ['ROLE_BRAND_MANAGER']);
    }

    public static function brandOwner(ContainerInterface $c): User
    {
        return self::findOrCreate($c, 'harness-owner@test.local', ['ROLE_BRAND_OWNER']);
    }

    /**
     * Бренд-владелец + бренд + связь BrandUser (owner).
     * Нужен для страниц /brand LK (getActiveBrand ищет BrandUser по пользователю) и checkout.
     *
     * @return array{0: User, 1: Brand}
     */
    public static function brandOwnerWithBrand(ContainerInterface $c): array
    {
        $em   = $c->get('doctrine.orm.entity_manager');
        $user = self::brandOwner($c);

        $existing = $em->getRepository(BrandUser::class)->findOneBy(['user' => $user]);
        if ($existing !== null) {
            return [$user, $existing->getBrand()];
        }

        $brand = (new Brand())
            ->setTitle('Test Brand')
            ->setSlug('test-brand-lk');
        $em->persist($brand);

        $link = (new BrandUser())
            ->setUser($user)
            ->setBrand($brand)
            ->setRole(BrandUser::ROLE_OWNER);
        $em->persist($link);

        $em->flush();

        return [$user, $brand];
    }

    private static function findOrCreate(ContainerInterface $c, string $email, array $roles): User
    {
        /** @var EntityManagerInterface $em */
        $em   = $c->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository(User::class);

        $user = $repo->findOneBy(['email' => $email]);
        if ($user !== null) {
            return $user;
        }

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
