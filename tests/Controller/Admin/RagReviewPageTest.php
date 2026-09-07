<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Brand;
use App\Entity\BrandRagPipeline;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Entity\User;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Прод 500 на /admin?routeName=admin_rag_review (07.09.2026, 17:18 МСК).
 * Корень: App\Service\VectorStoreService (конструкторская зависимость
 * RagDashboardController) требовал %env(QDRANT_COLLECTION)% без дефолта —
 * на проде .env/.env.local не задают RAG-стек (он живёт на Mac/LLM-сервере),
 * поэтому конструирование контроллера падало с EnvNotFoundException
 * на ЛЮБОМ /admin/rag/* роуте, а не только на /review.
 *
 * Проверяем страницу верификации брендов (RagDashboardController::review())
 * как при пустой очереди (текущее состояние прода — brand_rag_pipeline пуст),
 * так и с брендом в статусе review (реальный кейс отказа модели).
 */
class RagReviewPageTest extends WebTestCase
{
    private ?string $originalQdrantCollection = null;
    private bool $qdrantCollectionEnvTouched = false;

    protected function tearDown(): void
    {
        if ($this->qdrantCollectionEnvTouched) {
            // Dotenv::bootEnv() по умолчанию НЕ вызывает putenv() (usePutenv=false с 5.1),
            // значение живёт только в $_ENV/$_SERVER — оттуда его и нужно восстанавливать
            // (getenv() тут вернёт false, даже когда .env.test задал переменную).
            if ($this->originalQdrantCollection === null) {
                unset($_ENV['QDRANT_COLLECTION'], $_SERVER['QDRANT_COLLECTION']);
            } else {
                $_ENV['QDRANT_COLLECTION'] = $this->originalQdrantCollection;
                $_SERVER['QDRANT_COLLECTION'] = $this->originalQdrantCollection;
            }
            $this->qdrantCollectionEnvTouched = false;
        }
        parent::tearDown();
    }

    /** Точно воспроизводит прод-окружение: QDRANT_COLLECTION нигде не задан. */
    public function testReviewPageRendersWithoutQdrantCollectionEnv(): void
    {
        $this->originalQdrantCollection = $_SERVER['QDRANT_COLLECTION'] ?? $_ENV['QDRANT_COLLECTION'] ?? null;
        $this->qdrantCollectionEnvTouched = true;
        putenv('QDRANT_COLLECTION');
        unset($_ENV['QDRANT_COLLECTION'], $_SERVER['QDRANT_COLLECTION']);

        $client = $this->adminClient();
        $client->request('GET', '/admin/rag/review');
        $this->assertResponseIsSuccessful();
    }

    private function adminClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $email = 'rag-review-admin-test@example.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($user === null) {
            $user = (new User())
                ->setEmail($email)
                ->setRoles(['ROLE_ADMIN'])
                ->setPassword('test-not-used');
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user, 'admin');
        return $client;
    }

    public function testReviewPageRendersWhenQueueEmpty(): void
    {
        $client = $this->adminClient();
        $client->request('GET', '/admin/rag/review');
        $this->assertResponseIsSuccessful();
    }

    public function testReviewPageRendersWithBrandPendingVerification(): void
    {
        $client = $this->adminClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $brand = (new Brand())
            ->setTitle('Тестовый бренд')
            ->setSlug('test-review-brand')
            ->setEmail('brand@example.com')
            ->setDescription('Отказ модели: недостаточно фактов о бренде.')
            ->setStatus(Statuses::New);
        $em->persist($brand);
        $em->flush();

        $pipeline = (new BrandRagPipeline())
            ->setBrand($brand)
            ->setStatus(BrandRagPipeline::STATUS_REVIEW)
            ->setLastError('review: отказ модели');
        $em->persist($pipeline);
        $em->flush();

        $client->request('GET', '/admin/rag/review');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Тестовый бренд');
    }

    /** Точный URL из прод-лога (EasyAdmin ?routeName= форма ссылки из меню). */
    public function testReviewPageViaRouteNameQueryParam(): void
    {
        $client = $this->adminClient();
        $client->request('GET', '/admin?routeName=admin_rag_review');
        $this->assertResponseIsSuccessful();
    }
}
