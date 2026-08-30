<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Brand;
use App\Entity\BrandModeration;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * PR 2: владелец самрег-бренда возвращает заявку на повторную проверку
 * (POST /brand/moderation/resubmit) + баннер модерации гаснет после публикации.
 *
 * Run with: php bin/phpunit tests/Controller/BrandModerationResubmitControllerTest.php
 */
class BrandModerationResubmitControllerTest extends AuthenticatedWebTestCase
{
    public function testGuestIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('POST', '/brand/moderation/resubmit');

        $this->assertResponseRedirects('/login', 302);
    }

    public function testCustomerCannotResubmit(): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        $this->loginAsCustomer($client);
        $client->request('POST', '/brand/moderation/resubmit');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testResubmitRequeuesAfterChangesRequestedCooldownExpired(): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        [, $brand] = $this->loginAsBrandOwnerWithBrand($client);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $this->setModeration($em, $brand, BrandModeration::STATUS_CHANGES_REQUESTED, 3, new \DateTimeImmutable('-2 hours'));

        $token = $this->resubmitToken($client);
        $client->request('POST', '/brand/moderation/resubmit', ['_token' => $token]);

        $this->assertResponseRedirects('/brand/dashboard');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'отправлена на повторную проверку');

        $em->clear();
        $moderation = $em->getRepository(BrandModeration::class)->findOneBy(['brand' => $brand]);
        $this->assertSame(BrandModeration::STATUS_QUEUED, $moderation->getStatus());
        $this->assertSame(0, $moderation->getAnalyzeAttempts());
    }

    public function testResubmitBlockedDuringCooldown(): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        [, $brand] = $this->loginAsBrandOwnerWithBrand($client);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $this->setModeration($em, $brand, BrandModeration::STATUS_CHANGES_REQUESTED, 0, new \DateTimeImmutable());

        $token = $this->resubmitToken($client);
        $client->request('POST', '/brand/moderation/resubmit', ['_token' => $token]);

        $this->assertResponseRedirects('/brand/dashboard');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'подождите');

        $em->clear();
        $moderation = $em->getRepository(BrandModeration::class)->findOneBy(['brand' => $brand]);
        $this->assertSame(BrandModeration::STATUS_CHANGES_REQUESTED, $moderation->getStatus());
    }

    #[DataProvider('ineligibleStatusesProvider')]
    public function testResubmitRejectedWhenStatusNotEligible(string $status): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        [, $brand] = $this->loginAsBrandOwnerWithBrand($client);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Кулдаун явно истёк — проверяем именно гард по статусу, а не по времени.
        $this->setModeration($em, $brand, $status, 0, new \DateTimeImmutable('-2 hours'));

        $token = $this->resubmitToken($client);
        $client->request('POST', '/brand/moderation/resubmit', ['_token' => $token]);

        $this->assertResponseRedirects('/brand/dashboard');

        $em->clear();
        $moderation = $em->getRepository(BrandModeration::class)->findOneBy(['brand' => $brand]);
        $this->assertSame($status, $moderation->getStatus());
    }

    public static function ineligibleStatusesProvider(): iterable
    {
        yield 'queued'   => [BrandModeration::STATUS_QUEUED];
        yield 'approved' => [BrandModeration::STATUS_APPROVED];
    }

    /** Регресс: строка 4 баннера сравнивала enum Statuses со строкой и всегда была true. */
    public function testModerationBannerHiddenWhenBrandIsPublished(): void
    {
        $this->skipIfNoDatabase();

        $client = static::createClient();
        [, $brand] = $this->loginAsBrandOwnerWithBrand($client);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Заявка есть (mod не null) — но карточка уже опубликована, баннер обязан молчать.
        // setModeration() делает $em->clear() (Doctrine ORM 3: clear() без аргумента детачит всё,
        // включая $brand) — перечитываем бренд заново, иначе setStatus()+flush() ниже молча не сохранится.
        $this->setModeration($em, $brand, BrandModeration::STATUS_CHANGES_REQUESTED, 0, new \DateTimeImmutable());
        $brand = $em->getRepository(Brand::class)->find($brand->getId());
        $brand->setStatus(Statuses::Active);
        $em->flush();

        $client->request('GET', '/brand/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('body', 'Карточка на проверке');
        $this->assertSelectorTextNotContains('body', 'Карточка пока не опубликована');
    }

    private function setModeration(
        EntityManagerInterface $em,
        Brand $brand,
        string $status,
        int $attempts,
        \DateTimeImmutable $updatedAt,
    ): BrandModeration {
        $moderation = $em->getRepository(BrandModeration::class)->findOneBy(['brand' => $brand]);
        if ($moderation === null) {
            $moderation = (new BrandModeration())->setBrand($brand);
            $em->persist($moderation);
            $em->flush();
        }

        // Прямой DQL UPDATE, а не setStatus()+flush(): PreUpdate-колбэк сущности (onUpdate())
        // при обычном flush() всегда переписывает updatedAt на «сейчас», backdate иначе не удержать.
        $em->createQuery(
            'UPDATE App\Entity\BrandModeration m SET m.status = :status, m.analyzeAttempts = :attempts, m.updatedAt = :updatedAt WHERE m.id = :id'
        )
            ->setParameter('status', $status)
            ->setParameter('attempts', $attempts)
            ->setParameter('updatedAt', $updatedAt)
            ->setParameter('id', $moderation->getId())
            ->execute();

        $em->clear();

        return $em->getRepository(BrandModeration::class)->find($moderation->getId());
    }

    /**
     * Форсирует CSRF-токен вне формы (см. аналогичный хелпер в BrandUploadValidationTest) —
     * токен генерируется в сессии последнего реального запроса клиента.
     */
    private function resubmitToken(KernelBrowser $client): string
    {
        $client->request('GET', '/brand/dashboard');
        $lastRequest = $client->getRequest();

        $requestStack = static::getContainer()->get('request_stack');
        $requestStack->push($lastRequest);
        $token = static::getContainer()->get('security.csrf.token_manager')->getToken('moderation_resubmit')->getValue();
        $requestStack->pop();
        $lastRequest->getSession()->save();

        return $token;
    }
}
