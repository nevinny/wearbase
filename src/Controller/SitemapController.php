<?php

namespace App\Controller;

use App\Repository\BrandRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'sitemap_xml', defaults: ['_format' => 'xml'])]
    public function sitemap(Request $request, BrandRepository $repo): Response
    {
        $urls = [];

        $baseUrl = $request->getSchemeAndHttpHost();

        $urls[] = [
            'loc' => $this->generateUrl('home_hub', ['_locale' => 'ru'], UrlGeneratorInterface::ABSOLUTE_URL),
            'changefreq' => 'daily',
            'priority' => '1.0',
            'alternates' => [
                ['locale' => 'ru', 'loc' => $this->generateUrl('home_hub', ['_locale' => 'ru'], UrlGeneratorInterface::ABSOLUTE_URL)],
                ['locale' => 'en', 'loc' => $this->generateUrl('home_hub', ['_locale' => 'en'], UrlGeneratorInterface::ABSOLUTE_URL)],
            ],
        ];

        $urls[] = [
            'loc' => $this->generateUrl('brand_index', ['_locale' => 'ru'], UrlGeneratorInterface::ABSOLUTE_URL),
            'changefreq' => 'daily',
            'priority' => '0.9',
            'alternates' => [
                ['locale' => 'ru', 'loc' => $this->generateUrl('brand_index', ['_locale' => 'ru'], UrlGeneratorInterface::ABSOLUTE_URL)],
                ['locale' => 'en', 'loc' => $this->generateUrl('brand_index', ['_locale' => 'en'], UrlGeneratorInterface::ABSOLUTE_URL)],
            ],
        ];

        $brands = $repo->createQueryBuilder('b')
            ->where('b.status = :status')
            ->setParameter('status', 'active')
            ->getQuery()
            ->getResult();

        foreach ($brands as $brand) {
            if ($brand->getSlug()) {
                $urls[] = [
                    'loc' => $this->generateUrl('brand_show', [
                        '_locale' => 'ru',
                        'slug' => $brand->getSlug()
                    ], UrlGeneratorInterface::ABSOLUTE_URL),
                    'changefreq' => 'weekly',
                    'priority' => $brand->getDescription() ? '0.7' : '0.5',
                    'lastmod' => $brand->getUpdatedAt()?->format('Y-m-d'),
                    'alternates' => [
                        ['locale' => 'ru', 'loc' => $this->generateUrl('brand_show', ['_locale' => 'ru', 'slug' => $brand->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL)],
                        ['locale' => 'en', 'loc' => $this->generateUrl('brand_show', ['_locale' => 'en', 'slug' => $brand->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL)],
                    ],
                ];
            }
        }

        $xml = $this->renderView('sitemap/xml.html.twig', [
            'urls' => $urls,
        ]);

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }
}