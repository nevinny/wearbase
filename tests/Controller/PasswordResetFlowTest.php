<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Сквозной сценарий восстановления пароля: заявка → письмо со ссылкой → смена пароля → вход.
 *
 * AuthControllerTest покрывает только страницу и submit с несуществующим email
 * (там ничего не происходит по определению) — реальная цепочка не проверялась.
 */
final class PasswordResetFlowTest extends DatabaseDependentWebTestCase
{
    private const EMAIL = 'harness-reset@test.local';
    private const OLD_PASSWORD = 'old-password-123';
    private const NEW_PASSWORD = 'new-password-456';

    private function createUser(): User
    {
        $c = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get('doctrine.orm.entity_manager');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);

        $user = $em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]) ?? new User();
        $user->setEmail(self::EMAIL);
        $user->setRoles(['ROLE_CUSTOMER']);
        $user->setPassword($hasher->hashPassword($user, self::OLD_PASSWORD));
        $user->setPasswordResetToken(null);
        $user->setPasswordResetRequestedAt(null);

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function reloadUser(): User
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();

        return $em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
    }

    public function testFullResetFlow(): void
    {
        $client = static::createClient();
        $this->skipIfNoDatabase();
        $this->createUser();

        // 1. Заявка на сброс
        $client->request('GET', '/forgot-password');
        $client->submitForm('Отправить ссылку', ['email' => self::EMAIL]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Письмо отправлено');

        // 2. Токен записан в БД
        $user = $this->reloadUser();
        $token = $user->getPasswordResetToken();
        $this->assertNotNull($token, 'Токен сброса не сохранён');
        $this->assertSame(64, strlen($token));
        $this->assertNotNull($user->getPasswordResetRequestedAt());

        // 3. Письмо ушло и содержит абсолютную ссылку с тем же токеном.
        // Хост берётся из RequestContext текущего запроса (в тесте — localhost),
        // а не из DEFAULT_URI: на проде это https://wearbase.ru (см. canonical).
        $this->assertEmailCount(1);
        $mail = $this->getMailerMessage();
        $this->assertEmailHtmlBodyContains($mail, '//localhost/reset-password/' . $token);
        $this->assertEmailAddressContains($mail, 'To', self::EMAIL);

        // 4. Страница сброса открывается
        $client->request('GET', '/reset-password/' . $token);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[type="password"]');

        // 5. Смена пароля
        $client->submitForm('Сохранить пароль', [
            'password' => self::NEW_PASSWORD,
        ]);
        $this->assertResponseRedirects('/login');

        // 6. Токен погашен
        $user = $this->reloadUser();
        $this->assertNull($user->getPasswordResetToken(), 'Токен не погашен после смены пароля');
        $this->assertNull($user->getPasswordResetRequestedAt());

        // 7. Новый пароль работает, старый — нет
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($user, self::NEW_PASSWORD));
        $this->assertFalse($hasher->isPasswordValid($user, self::OLD_PASSWORD));

        // 8. Повторное использование той же ссылки отбивается
        $client->request('GET', '/reset-password/' . $token);
        $this->assertResponseRedirects('/login');
    }

    public function testLoginWithNewPasswordSucceeds(): void
    {
        $client = static::createClient();
        $this->skipIfNoDatabase();

        $c = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get('doctrine.orm.entity_manager');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);

        $user = $this->createUser();
        $user->setPassword($hasher->hashPassword($user, self::NEW_PASSWORD));
        $em->flush();

        $client->request('GET', '/login');
        $client->submitForm('Войти', [
            '_username' => self::EMAIL,
            '_password' => self::NEW_PASSWORD,
        ]);

        $this->assertResponseRedirects();
        $this->assertStringNotContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testExpiredTokenIsRejectedAndCleared(): void
    {
        $client = static::createClient();
        $this->skipIfNoDatabase();

        $c = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get('doctrine.orm.entity_manager');

        $user = $this->createUser();
        $token = bin2hex(random_bytes(32));
        $user->setPasswordResetToken($token);
        $user->setPasswordResetRequestedAt(new \DateTimeImmutable('-2 hours'));
        $em->flush();

        $client->request('GET', '/reset-password/' . $token);
        $this->assertResponseRedirects('/forgot-password');

        $user = $this->reloadUser();
        $this->assertNull($user->getPasswordResetToken(), 'Протухший токен не вычищен');
    }

    public function testUnknownTokenRedirectsToLogin(): void
    {
        $client = static::createClient();
        $this->skipIfNoDatabase();

        $client->request('GET', '/reset-password/' . str_repeat('a', 64));
        $this->assertResponseRedirects('/login');
    }

    public function testShortPasswordIsRejectedAndTokenSurvives(): void
    {
        $client = static::createClient();
        $this->skipIfNoDatabase();

        $c = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get('doctrine.orm.entity_manager');

        $user = $this->createUser();
        $token = bin2hex(random_bytes(32));
        $user->setPasswordResetToken($token);
        $user->setPasswordResetRequestedAt(new \DateTimeImmutable());
        $em->flush();

        $client->request('GET', '/reset-password/' . $token);
        $client->submitForm('Сохранить пароль', [
            'password' => 'short',
        ]);

        $this->assertResponseRedirects('/reset-password/' . $token);

        $user = $this->reloadUser();
        $this->assertSame($token, $user->getPasswordResetToken(), 'Токен пропал после неудачной попытки');
        $this->assertTrue(
            static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, self::OLD_PASSWORD),
            'Пароль изменился несмотря на ошибку валидации'
        );
    }

    public function testNoEmailSentForUnknownAddress(): void
    {
        $client = static::createClient();
        $this->skipIfNoDatabase();

        $client->request('GET', '/forgot-password');
        $client->submitForm('Отправить ссылку', ['email' => 'definitely-not-here@test.local']);

        $this->assertSelectorTextContains('body', 'Письмо отправлено');
        $this->assertEmailCount(0);
    }
}
