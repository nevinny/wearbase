<?php

declare(strict_types=1);

namespace App\Controller\BrandLk;

use App\Entity\Product;
use App\Entity\ProductImage;
use App\Entity\ProductTranslation;
use App\Entity\ProductVariant;
use App\Form\BrandLk\ProductFormType;
use App\Form\BrandLk\ProductVariantFormType;
use App\Repository\LanguageRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductTranslationRepository;
use App\Service\ProductImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/brand/products', name: 'brand_product')]
class BrandProductController extends BrandDashboardController
{
    #[Route('', name: 's')]
    public function index(ProductRepository $repo): Response
    {
        $brand    = $this->getActiveBrand();
        $products = $repo->findBy(['brand' => $brand], ['id' => 'DESC']);

        return $this->render('brand_lk/products/index.html.twig', [
            'brand'    => $brand,
            'products' => $products,
        ]);
    }

    #[Route('/new', name: '_new')]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
        ProductRepository $productRepo,
    ): Response {
        $brand   = $this->getActiveBrand();
        $product = new Product();
        $product->setBrand($brand);

        $subscription = $this->getActiveSubscription();
        if ($subscription !== null) {
            $maxProducts = $subscription->getTariff()?->getMaxProducts();
            if ($maxProducts !== null && $brand->getProducts()->count() >= $maxProducts) {
                $this->addFlash('error', 'Достигнут лимит товаров для вашего тарифа.');
                return $this->redirectToRoute('brand_products');
            }
        }

        $form = $this->createForm(ProductFormType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$product->getSlug()) {
                $product->setSlug(
                    $this->generateUniqueProductSlug($slugger, $productRepo, $brand, $product->getTitle())
                );
            }
            $em->persist($product);
            $em->flush();

            $this->addFlash('success', 'Товар создан. Добавьте варианты и фотографии.');
            return $this->redirectToRoute('brand_product_variants', ['id' => $product->getId()]);
        }

        return $this->render('brand_lk/products/form.html.twig', [
            'brand'   => $brand,
            'product' => $product,
            'form'    => $form,
            'title'   => 'Новый товар',
        ]);
    }

    #[Route('/{id}/edit', name: '_edit')]
    public function edit(
        Product $product,
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
        ProductRepository $productRepo,
    ): Response {
        $brand = $this->getActiveBrand();
        $this->denyUnlessOwns($product, $brand);

        $form = $this->createForm(ProductFormType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$product->getSlug()) {
                $product->setSlug(
                    $this->generateUniqueProductSlug($slugger, $productRepo, $brand, $product->getTitle())
                );
            }
            $em->flush();
            $this->addFlash('success', 'Товар обновлён');
            return $this->redirectToRoute('brand_product_edit', ['id' => $product->getId()]);
        }

        return $this->render('brand_lk/products/form.html.twig', [
            'brand'   => $brand,
            'product' => $product,
            'form'    => $form,
            'title'   => 'Редактировать товар',
        ]);
    }

    // ── Варианты ──────────────────────────────────────────────

    #[Route('/{id}/variants', name: '_variants')]
    public function variants(
        Product $product,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        $brand = $this->getActiveBrand();
        $this->denyUnlessOwns($product, $brand);

        $newVariant = new ProductVariant();
        $form       = $this->createForm(ProductVariantFormType::class, $newVariant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newVariant->setProduct($product);
            $em->persist($newVariant);
            $em->flush();
            $this->addFlash('success', 'Вариант добавлен');
            return $this->redirectToRoute('brand_product_variants', ['id' => $product->getId()]);
        }

        return $this->render('brand_lk/products/variants.html.twig', [
            'brand'   => $brand,
            'product' => $product,
            'form'    => $form,
        ]);
    }

    #[Route('/{id}/variants/{variantId}/delete', name: '_variant_delete', methods: ['POST'])]
    public function deleteVariant(
        Product $product,
        int $variantId,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('brand_product', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('brand_product_variants', ['id' => $product->getId()]);
        }

        $brand = $this->getActiveBrand();
        $this->denyUnlessOwns($product, $brand);

        foreach ($product->getVariants() as $variant) {
            if ($variant->getId() === $variantId) {
                $em->remove($variant);
                break;
            }
        }
        $em->flush();

        return $this->redirectToRoute('brand_product_variants', ['id' => $product->getId()]);
    }

    // ── Изображения ───────────────────────────────────────────

    #[Route('/{id}/images', name: '_images')]
    public function images(Product $product): Response
    {
        $brand = $this->getActiveBrand();
        $this->denyUnlessOwns($product, $brand);

        return $this->render('brand_lk/products/images.html.twig', [
            'brand'   => $brand,
            'product' => $product,
        ]);
    }

    #[Route('/{id}/images/upload', name: '_images_upload', methods: ['POST'])]
    public function uploadImages(
        Product $product,
        Request $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('brand_product_images', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Недействительный токен'], 403);
        }

        $brand = $this->getActiveBrand();
        $this->denyUnlessOwns($product, $brand);

        $files = $request->files->get('images', []);
        if (!is_array($files)) {
            $files = [$files];
        }

        $isFirst  = $product->getProductImages()->isEmpty();
        $uploaded = 0;

        foreach ($files as $file) {
            if (!$file) {
                continue;
            }
            $image = new ProductImage();
            $image->setProduct($product);
            $image->setPreviewFile($file);
            $image->setIsMain($isFirst && $uploaded === 0);
            $em->persist($image);
            $uploaded++;
        }

        $em->flush();

        return $this->json(['uploaded' => $uploaded]);
    }

    #[Route('/{id}/images/{imageId}/delete', name: '_image_delete', methods: ['POST'])]
    public function deleteImage(
        Product $product,
        int $imageId,
        Request $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('brand_product_images', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Недействительный токен'], 403);
        }

        $brand = $this->getActiveBrand();
        $this->denyUnlessOwns($product, $brand);

        foreach ($product->getProductImages() as $image) {
            if ($image->getId() === $imageId) {
                $em->remove($image);
                break;
            }
        }
        $em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/{id}/images/sort', name: '_images_sort', methods: ['POST'])]
    public function sortImages(
        Product $product,
        Request $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('brand_product_images', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Недействительный токен'], 403);
        }

        $brand = $this->getActiveBrand();
        $this->denyUnlessOwns($product, $brand);

        $order = $request->toArray()['order'] ?? [];
        foreach ($product->getProductImages() as $image) {
            $pos = array_search($image->getId(), $order);
            if ($pos !== false) {
                $image->setSort((int) $pos);
                $image->setIsMain($pos === 0);
            }
        }
        $em->flush();

        return $this->json(['ok' => true]);
    }

    // ── Публикация ────────────────────────────────────────────

    #[Route('/{id}/publish', name: '_publish', methods: ['POST'])]
    public function publish(Product $product, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('brand_product', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('brand_products');
        }

        $brand = $this->getActiveBrand();
        $this->denyUnlessOwns($product, $brand);

        $newStatus = $product->getStatus() === 'active' ? 'draft' : 'active';
        $product->setStatus($newStatus);
        $em->flush();

        $this->addFlash('success', $newStatus === 'active' ? 'Товар опубликован' : 'Товар снят с публикации');
        return $this->redirectToRoute('brand_products');
    }

    // ── Импорт ───────────────────────────────────────────────

    // ── Переводы ────────────────────────────────────────────

    #[Route('/{id}/translations', name: '_translations')]
    public function translations(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        LanguageRepository $langRepo,
        ProductTranslationRepository $transRepo,
    ): Response {
        $brand   = $this->getActiveBrand();
        $product = $em->getRepository(Product::class)->find($id);
        if (!$product) throw $this->createNotFoundException();
        $this->denyUnlessOwns($product, $brand);

        $locales = $langRepo->findActive();
        $existing = $transRepo->findAllForProduct($product);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('product_translations', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Недействительный токен');
                return $this->redirectToRoute('brand_product_translations', ['id' => $id]);
            }
            foreach ($locales as $lang) {
                $locale = $lang->getCode();
                if ($locale === 'ru') continue;

                $t = $existing[$locale] ?? (new ProductTranslation())
                    ->setProduct($product)
                    ->setLocale($locale);

                $data = $request->request->all("trans_{$locale}");
                $t->setTitle($data['title'] ?? null);
                $t->setAnons($data['anons'] ?? null);
                $t->setDescription($data['description'] ?? null);
                $t->setMetaTitle($data['metaTitle'] ?? null);
                $t->setMetaDescription($data['metaDescription'] ?? null);

                if (!isset($existing[$locale])) {
                    $em->persist($t);
                    $existing[$locale] = $t;
                }
            }
            $em->flush();
            $this->addFlash('success', 'Переводы сохранены');
            return $this->redirectToRoute('brand_product_translations', ['id' => $id]);
        }

        return $this->render('brand_lk/products/translations.html.twig', [
            'brand'    => $brand,
            'product'  => $product,
            'locales'  => $locales,
            'existing' => $existing,
        ]);
    }

    // ── Импорт ──────────────────────────────────────────────

    #[Route('/import', name: '_import')]
    public function import(Request $request, ProductImportService $importer): Response
    {
        $brand = $this->getActiveBrand();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('product_import', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Недействительный токен');
                return $this->redirectToRoute('brand_product_import');
            }
            $file = $request->files->get('import_file');

            if (!$file) {
                $this->addFlash('error', 'Выберите файл для загрузки');
                return $this->redirectToRoute('brand_product_import');
            }

            $result = $importer->import($file, $brand);

            if (!empty($result['errors']) && $result['created'] === 0 && $result['updated'] === 0) {
                foreach ($result['errors'] as $error) {
                    $this->addFlash('error', $error);
                }
                return $this->redirectToRoute('brand_product_import');
            }

            $this->addFlash(
                'success',
                sprintf(
                    'Импорт завершён: создано %d, обновлено %d вариантов%s',
                    $result['created'],
                    $result['updated'],
                    $result['skipped'] > 0 ? ", пропущено {$result['skipped']}" : ''
                )
            );

            if (!empty($result['errors'])) {
                foreach (array_slice($result['errors'], 0, 10) as $error) {
                    $this->addFlash('warning', $error);
                }
                if (count($result['errors']) > 10) {
                    $this->addFlash('warning', sprintf('... и ещё %d ошибок', count($result['errors']) - 10));
                }
            }

            return $this->redirectToRoute('brand_products');
        }

        return $this->render('brand_lk/products/import.html.twig', [
            'brand' => $brand,
        ]);
    }

    #[Route('/import/template', name: '_import_template')]
    public function importTemplate(): BinaryFileResponse
    {
        $path = $this->getParameter('kernel.project_dir') . '/public/downloads/wearbase-import-template.xlsx';

        if (!file_exists($path)) {
            throw $this->createNotFoundException('Шаблон не найден');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'wearbase-import-template.xlsx'
        );
        return $response;
    }

    // ── Helpers ───────────────────────────────────────────────

    private function generateUniqueProductSlug(SluggerInterface $slugger, ProductRepository $repo, \App\Entity\Brand $brand, string $title): string
    {
        $base = strtolower((string) $slugger->slug($title));
        $slug = $base;
        $i    = 1;

        while ($repo->findOneBy(['brand' => $brand, 'slug' => $slug])) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    private function denyUnlessOwns(Product $product, \App\Entity\Brand $brand): void
    {
        if ($product->getBrand() !== $brand) {
            throw $this->createAccessDeniedException();
        }
    }
}
