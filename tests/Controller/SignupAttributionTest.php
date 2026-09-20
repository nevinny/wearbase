<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\EventListener\SignupAttributionListener;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Атрибуция первого касания (docs/registration_sources_2026_09.md):
 *  - первый GET без куки wb_src запоминает utm-метки/referrer/лендинг в куке;
 *  - повторный GET куку не перезаписывает (нужно именно первое касание);
 *  - регистрация переносит сырые поля куки в signup_* колонки User и метит
 *    редирект после логина параметром ?signup=... для цели Метрики.
 */
class SignupAttributionTest extends AuthenticatedWebTestCase
{
    public function testFirstTouchSetsAttributionCookie(): void
    {
        $client = static::createClient();
        $client->request(
            'GET',
            // абсолютный https: кука ставится Secure по схеме запроса (на проде всегда HTTPS)
            'https://localhost/login?utm_source=chatgpt.com&utm_medium=referral&utm_campaign=launch',
            [],
            [],
            ['HTTP_REFERER' => 'https://chatgpt.com/c/abc123'],
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseHasCookie(SignupAttributionListener::COOKIE_NAME);

        $cookie = $this->findWbSrcCookie($client);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());

        $data = json_decode((string) $cookie->getValue(), true);
        $this->assertSame('chatgpt.com', $data['utm_source']);
        $this->assertSame('referral', $data['utm_medium']);
        $this->assertSame('launch', $data['utm_campaign']);
        $this->assertSame('chatgpt.com/c/abc123', $data['ref']);
        $this->assertSame('/login', $data['lp']);
        $this->assertArrayHasKey('ts', $data);
    }

    public function testCookieNotOverwrittenOnSecondRequest(): void
    {
        $client = static::createClient();
        $client->getCookieJar()->set(new BrowserKitCookie(
            SignupAttributionListener::COOKIE_NAME,
            json_encode(['utm_source' => 'seed', 'ref' => null, 'lp' => '/', 'ts' => (new \DateTimeImmutable())->format(DATE_ATOM)]),
            null,
            '/',
            '',
        ));

        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertResponseNotHasCookie(SignupAttributionListener::COOKIE_NAME);
    }

    public function testRegistrationFillsSignupFieldsAndTagsRedirect(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();

        $wbSrc = json_encode([
            'utm_source'   => null,
            'utm_medium'   => null,
            'utm_campaign' => null,
            'ref'          => 'chatgpt.com/c/abc123',
            'lp'           => '/ru/style/kapsulnyi-garderob',
            'ts'           => (new \DateTimeImmutable('-2 minutes'))->format(DATE_ATOM),
        ]);
        $client->getCookieJar()->set(new BrowserKitCookie(SignupAttributionListener::COOKIE_NAME, $wbSrc, null, '/', ''));

        $crawler = $client->request('GET', '/register');
        $this->assertResponseIsSuccessful();

        $email = 'signup-attr-' . uniqid() . '@example.com';
        $client->request('POST', '/register', [
            'registration_form' => [
                'firstName' => 'Анна',
                'email' => $email,
                'plainPassword' => ['first' => 'Passw0rd!123', 'second' => 'Passw0rd!123'],
                'agreeTerms' => '1',
                '_token' => $crawler->filter('input[name="registration_form[_token]"]')->attr('value'),
            ],
            'cf-turnstile-response' => 'dummy',
        ]);

        $this->assertResponseStatusCodeSame(302);
        $location = $client->getResponse()->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringContainsString('signup=customer', $location);

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        $this->assertNotNull($user, 'Пользователь должен создаваться');
        $this->assertSame('chatgpt', $user->getSignupSource());
        $this->assertSame('/ru/style/kapsulnyi-garderob', $user->getSignupLanding());
        $this->assertSame('chatgpt.com/c/abc123', $user->getSignupReferrer());
        $this->assertNotNull($user->getSignupFirstSeenAt());

        // /account рендерится напрямую, без промежуточного редиректа — иначе
        // ?signup=customer терялся бы и цель Метрики не сработала бы.
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
    }

    private function findWbSrcCookie(KernelBrowser $client): ?Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === SignupAttributionListener::COOKIE_NAME) {
                return $cookie;
            }
        }

        return null;
    }
}
