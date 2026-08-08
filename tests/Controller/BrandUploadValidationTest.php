<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Brand;
use App\Entity\Product;
use App\Entity\ProductImage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * P0-регресс: логотип бренда (VichImageType без constraints, Brand::$logoFile без
 * единого Assert) и фото товара (ручной цикл в BrandProductController без валидации)
 * принимали любой файл и отдавали его с домена wearbase.ru — можно было залить SVG
 * со <script> (stored XSS на origin, угон сессии /admin).
 *
 * Фикс: Assert\Image на Brand::$logoFile (форма валидирует data_class-объект
 * автоматически при submit) + ручная Image-валидация в BrandProductController::uploadImages
 * по образцу уже существующего BrandMediaController::upload().
 *
 * Run with: php bin/phpunit tests/Controller/BrandUploadValidationTest.php
 */
class BrandUploadValidationTest extends AuthenticatedWebTestCase
{
    private const PROFILE_FORM_NAME = 'brand_profile_form';

    /** @var string[] absolute paths of files created by tests, cleaned up in tearDown */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoDatabase();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tmpFiles = [];
        parent::tearDown();
    }

    // ── Логотип бренда: /brand/profile ─────────────────────────────────────

    public function testSvgLogoIsRejected(): void
    {
        $client = static::createClient();
        [, $brand] = $this->loginAsBrandOwnerWithBrand($client);
        $brandId = $brand->getId();

        $crawler = $client->request('GET', '/brand/profile');
        $form = $crawler->filter('form')->form();
        $form[self::PROFILE_FORM_NAME . '[logoFile][file]']->upload($this->makeSvgFile());

        $client->submit($form);

        // Форма невалидна → Symfony AbstractController::render() автоматически возвращает
        // 422 для submitted-but-invalid формы (см. AbstractController::render()), а не
        // редирект на успех.
        $this->assertResponseStatusCodeSame(422);

        $this->assertBrandLogoUnchanged($brandId);
    }

    public function testPngWithSvgContentAsLogoIsRejected(): void
    {
        $client = static::createClient();
        [, $brand] = $this->loginAsBrandOwnerWithBrand($client);
        $brandId = $brand->getId();

        $crawler = $client->request('GET', '/brand/profile');
        $form = $crawler->filter('form')->form();
        // Реальный контент — SVG/HTML, расширение обманчиво .png: детект должен идти по содержимому.
        $form[self::PROFILE_FORM_NAME . '[logoFile][file]']->upload($this->makeSvgContentWithPngExtension());

        $client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $this->assertBrandLogoUnchanged($brandId);
    }

    public function testValidPngLogoIsAccepted(): void
    {
        $client = static::createClient();
        [, $brand] = $this->loginAsBrandOwnerWithBrand($client);
        $brandId = $brand->getId();

        $crawler = $client->request('GET', '/brand/profile');
        $form = $crawler->filter('form')->form();
        $form[self::PROFILE_FORM_NAME . '[logoFile][file]']->upload($this->makeValidPng());

        $client->submit($form);

        $this->assertResponseRedirects('/brand/profile');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();
        /** @var Brand $reloaded */
        $reloaded = $em->getRepository(Brand::class)->find($brandId);
        $this->assertNotNull($reloaded->getLogo(), 'Валидный PNG должен был сохраниться как логотип');

        /** @var StorageInterface $storage */
        $storage = static::getContainer()->get(StorageInterface::class);
        $this->tmpFiles[] = $storage->resolvePath($reloaded, 'logoFile');
    }

    private function assertBrandLogoUnchanged(int $brandId): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();
        /** @var Brand $reloaded */
        $reloaded = $em->getRepository(Brand::class)->find($brandId);
        $this->assertNull($reloaded->getLogo(), 'Невалидный файл не должен был сохраниться как логотип');
    }

    // ── Фото товара: /brand/products/{id}/images/upload ─────────────────────

    public function testSvgProductImageIsRejected(): void
    {
        $client = static::createClient();
        [, $brand] = $this->loginAsBrandOwnerWithBrand($client);
        $product = $this->makeProduct($brand);

        $client->request('GET', '/brand/products/' . $product->getId() . '/images');
        $token = $this->forceCsrfToken($client->getRequest(), 'brand_product_images');

        $svg = new UploadedFile($this->makeSvgFile(), 'evil.svg', 'image/svg+xml', null, true);

        $client->request(
            'POST',
            '/brand/products/' . $product->getId() . '/images/upload',
            [],
            ['images' => [$svg]],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(0, $data['uploaded']);
        $this->assertCount(1, $data['errors']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->assertCount(0, $em->getRepository(ProductImage::class)->findBy(['product' => $product]));
    }

    public function testValidPngProductImageIsAccepted(): void
    {
        $client = static::createClient();
        [, $brand] = $this->loginAsBrandOwnerWithBrand($client);
        $product = $this->makeProduct($brand);

        $client->request('GET', '/brand/products/' . $product->getId() . '/images');
        $token = $this->forceCsrfToken($client->getRequest(), 'brand_product_images');

        $png = new UploadedFile($this->makeValidPng(), 'photo.png', 'image/png', null, true);

        $client->request(
            'POST',
            '/brand/products/' . $product->getId() . '/images/upload',
            [],
            ['images' => [$png]],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(1, $data['uploaded']);
        $this->assertSame([], $data['errors']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $images = $em->getRepository(ProductImage::class)->findBy(['product' => $product]);
        $this->assertCount(1, $images);

        /** @var StorageInterface $storage */
        $storage = static::getContainer()->get(StorageInterface::class);
        $this->tmpFiles[] = $storage->resolvePath($images[0], 'previewFile');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function makeProduct(Brand $brand): Product
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $product = new Product();
        $product->setBrand($brand);
        $product->setTitle('Test product ' . uniqid());
        $product->setSlug('test-product-' . uniqid());
        $em->persist($product);
        $em->flush();

        return $product;
    }

    private function makeValidPng(): string
    {
        $path = sys_get_temp_dir() . '/brand_upload_test_' . uniqid() . '.png';
        $im = imagecreatetruecolor(4, 4);
        imagepng($im, $path);
        imagedestroy($im);
        $this->tmpFiles[] = $path;

        return $path;
    }

    private function makeSvgFile(): string
    {
        $path = sys_get_temp_dir() . '/brand_upload_test_' . uniqid() . '.svg';
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>');
        $this->tmpFiles[] = $path;

        return $path;
    }

    private function makeSvgContentWithPngExtension(): string
    {
        $path = sys_get_temp_dir() . '/brand_upload_test_' . uniqid() . '.png';
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>');
        $this->tmpFiles[] = $path;

        return $path;
    }

    /**
     * Форсирует CSRF-токен для JSON-эндпоинта (см. аналогичный хелпер в
     * WardrobeIngestControllerTest) — токен генерируется в сессии последнего
     * реального запроса клиента, иначе не совпадёт с тем, что проверит контроллер.
     */
    private function forceCsrfToken(Request $lastRequest, string $tokenId): string
    {
        $requestStack = static::getContainer()->get('request_stack');
        $requestStack->push($lastRequest);
        $token = static::getContainer()->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        $requestStack->pop();
        $lastRequest->getSession()->save();

        return $token;
    }
}
