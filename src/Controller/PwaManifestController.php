<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class PwaManifestController extends AbstractController
{
    #[Route('/manifest.webmanifest', name: 'pwa_manifest', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return $this->json([
            'id' => '/account/wardrobe-app',
            'name' => 'WEARBASE — семейный гардероб',
            'short_name' => 'WEARBASE',
            'description' => 'Семейный гардероб, покупки, примерки и образы',
            'lang' => 'ru',
            'dir' => 'ltr',
            'start_url' => '/account/wardrobe-app',
            'scope' => '/account/',
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'background_color' => '#f8fafc',
            'theme_color' => '#4f46e5',
            'icons' => [
                ['src' => '/images/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/images/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ],
            'shortcuts' => [
                ['name' => 'Семья', 'short_name' => 'Семья', 'url' => '/account/family'],
                ['name' => 'Главная гардероба', 'short_name' => 'Гардероб', 'url' => '/account/wardrobe-app'],
                ['name' => 'Мои вещи', 'short_name' => 'Вещи', 'url' => '/account/wardrobe'],
            ],
        ], headers: [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
