<?php

declare(strict_types=1);

namespace App\Controller\BrandLk;

use App\Entity\BrandImage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/brand/media', name: 'brand_media')]
class BrandMediaController extends BrandDashboardController
{
    #[Route('', name: '')]
    public function index(): Response
    {
        $brand = $this->getActiveBrand();

        return $this->render('brand_lk/media.html.twig', [
            'brand'  => $brand,
            'images' => $brand->getImages(),
        ]);
    }

    #[Route('/upload', name: '_upload', methods: ['POST'])]
    public function upload(
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('brand_media', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Недействительный токен'], 403);
        }

        $brand = $this->getActiveBrand();

        $subscription = $this->getActiveSubscription();
        if ($subscription !== null) {
            $maxImages = $subscription->getTariff()?->getMaxImages();
            if ($maxImages !== null && $brand->getImages()->count() >= $maxImages) {
                return $this->json(['error' => 'Достигнут лимит изображений для вашего тарифа.'], 403);
            }
        }

        $files = $request->files->get('images', []);

        if (!is_array($files)) {
            $files = [$files];
        }

        $nextSort = $brand->getImages()->count();

        $uploaded = 0;
        foreach ($files as $file) {
            if (!$file) {
                continue;
            }

            $violations = $validator->validate($file, new Image([
                'maxSize' => '5M',
                'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                'mimeTypesMessage' => 'Допустимы только JPEG, PNG и WebP',
            ]));
            if ($violations->count() > 0) {
                return $this->json(['error' => $violations->get(0)->getMessage()], 422);
            }

            $image = new BrandImage();
            $image->setBrand($brand);
            $image->setPreviewFile($file);
            $image->setSortOrder($nextSort++);
            $em->persist($image);
            $uploaded++;
        }

        $em->flush();

        return $this->json(['uploaded' => $uploaded]);
    }

    #[Route('/delete/{id}', name: '_delete', methods: ['POST'])]
    public function delete(
        BrandImage $image,
        Request $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('brand_media', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Недействительный токен'], 403);
        }

        $brand = $this->getActiveBrand();

        if ($image->getBrand() !== $brand) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $em->remove($image);
        $em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/sort', name: '_sort', methods: ['POST'])]
    public function sort(
        Request $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('brand_media', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Недействительный токен'], 403);
        }

        $brand = $this->getActiveBrand();
        $order = $request->toArray()['order'] ?? [];
        $imagesById = [];

        foreach ($brand->getImages() as $image) {
            $imagesById[$image->getId()] = $image;
        }

        foreach ($order as $position => $imageId) {
            $image = $imagesById[(int) $imageId] ?? null;
            if ($image) {
                $image->setSortOrder($position);
            }
        }

        $em->flush();

        return $this->json(['ok' => true]);
    }
}
