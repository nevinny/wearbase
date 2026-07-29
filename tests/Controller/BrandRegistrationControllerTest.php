<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\BrandModeration;
use App\Entity\BrandUser;
use App\Entity\Subscription;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use App\Entity\User;

/**
 * Самостоятельная регистрация бренда `/register?brand=1` — главный путь в ЛК с лендинга
 * for-brands (sales_offer.md §11.2-bis).
 *
 * Регрессия 2026-07-26: отдавала 500. SubscriptionFactory::createFreeTrial() искал активную
 * подписку по ещё не сброшенному в БД Brand → Doctrine ORMInvalidArgumentException
 * («Binding entities to query parameters only allowed for entities that have an identifier»).
 * Тест держит весь путь: аккаунт + бренд + связь владельца + free-trial.
 */
class BrandRegistrationControllerTest extends DatabaseDependentWebTestCase
{
    public function testBrandRegistrationCreatesAccountBrandAndTrial(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();

        $crawler = $client->request('GET', '/register?brand=1');
        $this->assertResponseIsSuccessful();

        $email = 'brand-reg-' . uniqid() . '@example.com';
        $client->request('POST', '/register?brand=1', [
            'brand_registration_form' => [
                'brandTitle' => 'Регистрационный Бренд',
                'firstName' => 'Иван',
                'email' => $email,
                'plainPassword' => ['first' => 'Passw0rd!123', 'second' => 'Passw0rd!123'],
                'agreeTerms' => '1',
                '_token' => $crawler->filter('input[name="brand_registration_form[_token]"]')->attr('value'),
            ],
            // Turnstile рендерится JS-виджетом: в тесте поле отправляем вручную
            // (dummy-ключи в .env.test — always-pass).
            'cf-turnstile-response' => 'dummy',
        ]);

        $this->assertResponseStatusCodeSame(302, 'После регистрации пользователь логинится и редиректится');

        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user, 'Аккаунт бренда должен создаваться');
        $this->assertContains('ROLE_BRAND_MANAGER', $user->getRoles());

        $link = $em->getRepository(BrandUser::class)->findOneBy(['user' => $user]);
        $this->assertNotNull($link, 'Владелец должен быть привязан к бренду');
        $this->assertSame(BrandUser::ROLE_OWNER, $link->getRole());
        $this->assertSame('Регистрационный Бренд', $link->getBrand()->getTitle());

        // Премодерация: карточка не должна попадать в каталог/sitemap по факту регистрации —
        // иначе бренд с одним названием минует ниша-гейт и origin-гейт.
        $this->assertSame(Statuses::New, $link->getBrand()->getStatus(), 'Новый бренд публикуется только после модерации');
        $this->assertFalse($link->getBrand()->isPublished());

        $subscription = $em->getRepository(Subscription::class)->findOneBy(['brand' => $link->getBrand()]);
        $this->assertNotNull($subscription, 'Должна создаваться free-trial подписка');
        $this->assertSame(Subscription::STATUS_TRIAL, $subscription->getStatus());

        // Премодерация (MVP авто-модерации самрег-брендов): заявка встаёт в очередь сразу.
        $moderation = $em->getRepository(BrandModeration::class)->findOneBy(['brand' => $link->getBrand()]);
        $this->assertNotNull($moderation, 'Регистрация бренда должна ставить заявку в очередь премодерации');
        $this->assertSame(BrandModeration::STATUS_QUEUED, $moderation->getStatus());
        $this->assertSame(BrandModeration::SOURCE_SELF_REGISTER, $moderation->getSource());
    }
}
