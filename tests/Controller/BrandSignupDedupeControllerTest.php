<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Brand;
use App\Entity\BrandUser;
use App\Entity\User;
use App\Repository\BrandRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Профилактика дублей брендов при регистрации (docs/brand_duplicates.md, инцидент iseymordc):
 *  1. регистрация из флоу /brand-claim/{id} не создаёт бренд и возвращает на заявку;
 *  2. гард точного совпадения имени/слага + чекбокс «Это другой бренд».
 */
class BrandSignupDedupeControllerTest extends DatabaseDependentWebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function makeBrand(string $title, string $slug): Brand
    {
        $brand = (new Brand())->setTitle($title)->setSlug($slug);
        $this->em()->persist($brand);
        $this->em()->flush();

        return $brand;
    }

    private function brandCount(): int
    {
        return (int) $this->em()->getRepository(Brand::class)->count([]);
    }

    private function postBrandForm(KernelBrowser $client, string $token, string $title, string $email, bool $notDuplicate = false): void
    {
        $data = [
            'brandTitle' => $title,
            'firstName' => 'Иван',
            'email' => $email,
            'plainPassword' => ['first' => 'Passw0rd!123', 'second' => 'Passw0rd!123'],
            'agreeTerms' => '1',
            '_token' => $token,
        ];
        if ($notDuplicate) {
            $data['notDuplicate'] = '1';
        }
        // Turnstile — JS-виджет: в тесте шлём поле вручную (dummy-ключи always-pass).
        $client->request('POST', '/register?brand=1', [
            'brand_registration_form' => $data,
            'cf-turnstile-response' => 'dummy',
        ]);
    }

    public function testClaimFlowLoginOffersPlainRegistrationAndRegisterCreatesNoBrand(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();
        $brand = $this->makeBrand('Лидовый Бренд', 'lidovyi-brend-' . uniqid());

        // Аноним на заявке → 302 на /login, URL заявки остаётся в сессии
        $client->request('GET', '/brand-claim/' . $brand->getId());
        $this->assertResponseRedirects();

        $client->request('GET', '/login');
        $this->assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        $this->assertStringContainsString('подтвердить владение брендом «Лидовый Бренд»', $html);
        $this->assertStringNotContainsString('Зарегистрировать бренд', $html);

        $crawler = $client->request('GET', '/register?brand=1');
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $crawler->filter('input[name="brand_registration_form[brandTitle]"]')->count(), 'форма бренда не показывается');

        $before = $this->brandCount();
        $email = 'claim-flow-' . uniqid() . '@example.com';
        $client->request('POST', '/register?brand=1', [
            'registration_form' => [
                'firstName' => 'Иван',
                'email' => $email,
                'plainPassword' => ['first' => 'Passw0rd!123', 'second' => 'Passw0rd!123'],
                'agreeTerms' => '1',
                '_token' => $crawler->filter('input[name="registration_form[_token]"]')->attr('value'),
            ],
            'cf-turnstile-response' => 'dummy',
        ]);

        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/brand-claim/' . $brand->getId(), (string) $client->getResponse()->headers->get('Location'));

        $this->em()->clear();
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user);
        $this->assertNotContains('ROLE_BRAND_MANAGER', $user->getRoles());
        $this->assertSame($before, $this->brandCount(), 'новый Brand не создан');
        $this->assertNull($this->em()->getRepository(BrandUser::class)->findOneBy(['user' => $user]));
    }

    public function testLoginWithoutClaimFlowKeepsBrandRegistrationLink(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();

        $client->request('GET', '/login');
        $this->assertStringContainsString('Зарегистрировать бренд', $client->getResponse()->getContent());
    }

    public function testExactNameGuardBlocksUntilCheckboxTicked(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();
        $existing = $this->makeBrand('Guard Test Brand', 'guard-test-brand-' . uniqid());

        $crawler = $client->request('GET', '/register?brand=1');
        $token = $crawler->filter('input[name="brand_registration_form[_token]"]')->attr('value');
        $this->assertSame(0, $crawler->filter('input[name="brand_registration_form[notDuplicate]"]')->count(), 'чекбокса на чистой форме нет');

        $before = $this->brandCount();
        $email = 'dup-guard-' . uniqid() . '@example.com';

        // «guard-test-brand!» нормализуется в то же «guardtestbrand»
        $this->postBrandForm($client, $token, 'guard-test-brand!', $email);

        $this->assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        $this->assertStringContainsString('Бренд «Guard Test Brand» уже есть в каталоге', $html);
        $this->assertStringContainsString('/brand-claim/' . $existing->getId(), $html);
        $this->assertStringContainsString('notDuplicate', $html);
        $this->assertStringContainsString($email, $html, 'введённое сохраняется');
        $this->assertSame($before, $this->brandCount(), 'ничего не создано');
        $this->assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => $email]));

        // Повтор с чекбоксом «Это другой бренд» → создаётся как раньше
        $this->postBrandForm($client, $token, 'guard-test-brand!', $email, true);

        $this->assertResponseStatusCodeSame(302);
        $this->assertSame($before + 1, $this->brandCount());
        $this->em()->clear();
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user);
        $this->assertNotNull($this->em()->getRepository(BrandUser::class)->findOneBy(['user' => $user]));
    }

    public function testUnpublishedMatchHasClaimLinkButNoBrandPageLink(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();
        $slug = 'lead-new-' . uniqid();
        $existing = $this->makeBrand('Lead New Brand', $slug);
        $existing->setStatus(\Nevinny\AdminCoreBundle\Enum\Statuses::New);
        $this->em()->flush();

        $crawler = $client->request('GET', '/register?brand=1');
        $this->postBrandForm($client, $crawler->filter('input[name="brand_registration_form[_token]"]')->attr('value'), 'lead-new-brand', 'lead-' . uniqid() . '@example.com');

        $html = $client->getResponse()->getContent();
        $this->assertStringContainsString('уже есть в нашей базе', $html);
        $this->assertStringContainsString('/brand-claim/' . $existing->getId(), $html);
        $this->assertStringNotContainsString('/brands/' . $slug, $html);
    }

    public function testForeignMatchHasNoClaimLinkButHasCheckbox(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();
        $existing = $this->makeBrand('Foreign Guard Brand', 'foreign-guard-' . uniqid());
        $existing->markOrigin('foreign', null, new \DateTimeImmutable());
        $this->em()->flush();

        $crawler = $client->request('GET', '/register?brand=1');
        $this->postBrandForm($client, $crawler->filter('input[name="brand_registration_form[_token]"]')->attr('value'), 'foreign guard brand', 'foreign-' . uniqid() . '@example.com');

        $html = $client->getResponse()->getContent();
        $this->assertStringContainsString('уже есть', $html);
        $this->assertStringNotContainsString('/brand-claim/' . $existing->getId(), $html);
        $this->assertStringContainsString('notDuplicate', $html);
    }

    public function testGuardMatchesBySlugAndIgnoresDeletedAndMerged(): void
    {
        $this->skipIfNoDatabase();
        static::createClient();
        $repo = static::getContainer()->get(BrandRepository::class);

        $slug = 'slug-only-' . uniqid();
        $live = $this->makeBrand('Совсем Другое Имя', $slug);
        $this->assertSame($live->getId(), $repo->findLiveExactDuplicate('Не совпадает вообще', $slug)?->getId());

        $gone = $this->makeBrand('Удалённый Бренд Икс', 'udalyonnyi-' . uniqid());
        $gone->softDelete();
        $this->em()->flush();
        $this->assertNull($repo->findLiveExactDuplicate('Удалённый Бренд Икс', 'udalyonnyi-brend-iks'));

        $this->assertNull($repo->findLiveExactDuplicate('Nothing Like This ' . uniqid(), 'nothing-like-' . uniqid()));
    }

    public function testNonMatchingNameCreatesBrandAsBefore(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();
        $this->makeBrand('Guard Other Brand', 'guard-other-brand-' . uniqid());

        $crawler = $client->request('GET', '/register?brand=1');
        $before = $this->brandCount();
        $this->postBrandForm(
            $client,
            $crawler->filter('input[name="brand_registration_form[_token]"]')->attr('value'),
            'Совершенно Уникальный ' . uniqid(),
            'unique-' . uniqid() . '@example.com',
        );

        $this->assertResponseStatusCodeSame(302);
        $this->assertSame($before + 1, $this->brandCount());
    }
}
