<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\BrandClaim;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Участник команды бренда не может подать заявку на владение: guard стоял только на
 * GET-форме, из-за чего повторный POST после авто-выдачи плодил pending-claim.
 *
 * Run with: php bin/phpunit tests/Controller/BrandClaimMemberGuardControllerTest.php
 */
class BrandClaimMemberGuardControllerTest extends AuthenticatedWebTestCase
{
    public function testEmailSendByTeamMemberCreatesNoClaim(): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        [, $brand] = $this->loginAsBrandOwnerWithBrand($client);

        $client->request('POST', '/brand-claim/'.$brand->getId().'/email/send', [
            '_token' => $this->csrfToken($client, 'brand_claim_email'),
        ]);

        $this->assertResponseRedirects('/brand/dashboard');
        $this->assertSame(0, $this->countClaims($brand->getId()));
    }

    public function testManualByTeamMemberCreatesNoClaim(): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        [, $brand] = $this->loginAsBrandOwnerWithBrand($client);

        $client->request('POST', '/brand-claim/'.$brand->getId().'/manual', [
            '_token'  => $this->csrfToken($client, 'brand_claim_manual'),
            'comment' => 'это мой бренд',
        ]);

        $this->assertResponseRedirects('/brand/dashboard');
        $this->assertSame(0, $this->countClaims($brand->getId()));
    }

    private function csrfToken(KernelBrowser $client, string $id): string
    {
        $client->request('GET', '/brand/dashboard');
        $lastRequest = $client->getRequest();

        $requestStack = static::getContainer()->get('request_stack');
        $requestStack->push($lastRequest);
        $token = static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
        $requestStack->pop();
        $lastRequest->getSession()->save();

        return $token;
    }

    private function countClaims(int $brandId): int
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        return count($em->getRepository(BrandClaim::class)->findBy(['brand' => $brandId]));
    }
}
