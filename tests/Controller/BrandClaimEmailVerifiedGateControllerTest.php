<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Brand;
use App\Entity\BrandClaim;
use App\Entity\BrandUser;
use App\Entity\Tariff;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Self-serve авто-выдача владения (код на email бренда) требует подтверждённой почты
 * заявителя: grantOwnership() иначе бросает DomainException, а finishVerification() не
 * должен превращать это в 500 — заявка уходит админу (email_verified), владение не выдано.
 *
 * Run with: php bin/phpunit tests/Controller/BrandClaimEmailVerifiedGateControllerTest.php
 */
class BrandClaimEmailVerifiedGateControllerTest extends AuthenticatedWebTestCase
{
    public function testAutoGrantWithUnverifiedEmailSendsClaimToAdminInsteadOfGrantingOwnership(): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        $user   = UserFactory::withEmail(static::getContainer(), 'harness-unverified-claimant@test.local', ['ROLE_USER']);
        $client->loginUser($user);
        $this->assertFalse($user->isEmailVerified());

        $brand = $this->brandWithCodeClaim($user, 'unverified-gate');

        $client->request('POST', '/brand-claim/'.$brand->getId().'/email/verify', [
            '_token' => $this->csrfToken($client, $brand, 'brand_claim_email'),
            'code'   => '123456',
        ]);

        $this->assertResponseIsSuccessful();

        $em = $this->em();
        $em->clear();
        $claim = $em->getRepository(BrandClaim::class)->findOneBy(['brand' => $brand->getId()]);
        $this->assertSame(BrandClaim::STATUS_EMAIL_VERIFIED, $claim->getStatus(), 'ждёт админа, не approved');
        $this->assertNull($em->getRepository(BrandUser::class)->findOneBy(['brand' => $brand->getId()]));
        $this->assertNotContains('ROLE_BRAND_OWNER', $em->getRepository(User::class)->find($user->getId())->getRoles());
    }

    public function testAutoGrantWithVerifiedEmailStillGrantsOwnership(): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        $user   = UserFactory::withEmail(static::getContainer(), 'harness-verified-claimant@test.local', ['ROLE_USER']);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->em()->flush();
        $this->seedFreeTariff();
        $client->loginUser($user);

        $brand = $this->brandWithCodeClaim($user, 'verified-gate');

        $client->request('POST', '/brand-claim/'.$brand->getId().'/email/verify', [
            '_token' => $this->csrfToken($client, $brand, 'brand_claim_email'),
            'code'   => '123456',
        ]);

        $this->assertResponseRedirects('/brand/dashboard');

        $em = $this->em();
        $em->clear();
        $claim = $em->getRepository(BrandClaim::class)->findOneBy(['brand' => $brand->getId()]);
        $this->assertSame(BrandClaim::STATUS_APPROVED, $claim->getStatus());
        $this->assertNotNull($em->getRepository(BrandUser::class)->findOneBy(['brand' => $brand->getId(), 'role' => BrandUser::ROLE_OWNER]));
    }

    /** Бренд с email + заявка пользователя с уже выданным (валидным) кодом. */
    private function brandWithCodeClaim(User $user, string $slug): Brand
    {
        $em = $this->em();

        $brand = (new Brand())->setTitle('Gate '.$slug)->setSlug($slug)->setEmail($slug.'@brand.test');
        $em->persist($brand);

        $claim = (new BrandClaim())
            ->setBrand($brand)
            ->setUser($user)
            ->setMethod(BrandClaim::METHOD_EMAIL_CODE)
            ->setVerificationCode('123456')
            ->setCodeExpiresAt(new \DateTimeImmutable('+10 minutes'));
        $em->persist($claim);
        $em->flush();

        return $brand;
    }

    private function seedFreeTariff(): void
    {
        $em = $this->em();
        if (!$em->getRepository(Tariff::class)->findOneBy(['code' => Tariff::CODE_FREE])) {
            $em->persist((new Tariff())->setName('Free')->setCode(Tariff::CODE_FREE)->setTrialDays(30)->setMaxProducts(10));
            $em->flush();
        }
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }

    /** CSRF-токен в сессии клиента: открываем форму заявки тем же пользователем. */
    private function csrfToken(KernelBrowser $client, Brand $brand, string $id): string
    {
        $client->request('GET', '/brand-claim/'.$brand->getId());
        $lastRequest = $client->getRequest();

        $requestStack = static::getContainer()->get('request_stack');
        $requestStack->push($lastRequest);
        $token = static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
        $requestStack->pop();
        $lastRequest->getSession()->save();

        return $token;
    }
}
