<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class OgImageController extends AbstractController
{
    #[Route('/og-image.svg', name: 'og_image', methods: ['GET'])]
    public function ogImage(): Response
    {
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#f9fafb"/>
      <stop offset="100%" style="stop-color:#e5e7eb"/>
    </linearGradient>
  </defs>
  <rect fill="url(#bg)" width="1200" height="630"/>
  <rect fill="#6366f1" x="0" y="0" width="12" height="630"/>
  <text x="100" y="240" font-family="system-ui, -apple-system, sans-serif" font-size="120" font-weight="bold" fill="#111827">
    WEARBASE
  </text>
  <text x="100" y="340" font-family="system-ui, -apple-system, sans-serif" font-size="48" fill="#6366f1">
    Каталог российских брендов одежды
  </text>
  <text x="100" y="420" font-family="system-ui, -apple-system, sans-serif" font-size="32" fill="#6b7280">
    340+ брендов из Москвы, Санкт-Петербурга и других городов России
  </text>
  <rect fill="#6366f1" x="100" y="460" width="160" height="8" rx="4"/>
  <text x="100" y="550" font-family="system-ui, -apple-system, sans-serif" font-size="28" fill="#374151">
    wearbase.ru
  </text>
</svg>
SVG;

        return new Response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'max-age=86400, public',
        ]);
    }
}