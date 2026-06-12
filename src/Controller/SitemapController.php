<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\BrandRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'sitemap_xml', defaults: ['_format' => 'xml'])]
    public function sitemap(Request $request, BrandRepository $repo, ArticleRepository $articleRepo, \App\Service\CitySlugger $citySlugger, UrlGeneratorInterface $urlGenerator): Response
    {
        $urls = [];

        // <loc> всегда от канонического хоста (не от request): sitemap, запрошенный
        // через www/dev-хост, не должен раздавать неканонические URL (дубль-хост в GSC).
        $siteBase = parse_url((string) $this->getParameter('app.site_base_url'));
        $context  = $urlGenerator->getContext();
        $context->setScheme($siteBase['scheme'] ?? 'https');
        $context->setHost($siteBase['host'] ?? 'wearbase.ru');

        $baseUrl = $request->getSchemeAndHttpHost();

        $urls[] = [
            'loc' => $this->generateUrl('home_hub', ['_locale' => 'ru'], UrlGeneratorInterface::ABSOLUTE_URL),
            'changefreq' => 'daily',
            'priority' => '1.0',
        ];

        $urls[] = [
            'loc' => $this->generateUrl('brand_index', ['_locale' => 'ru'], UrlGeneratorInterface::ABSOLUTE_URL),
            'changefreq' => 'daily',
            'priority' => '0.9',
        ];

        $urls[] = [
            'loc' => $this->generateUrl('landing_no_marketplace', ['_locale' => 'ru'], UrlGeneratorInterface::ABSOLUTE_URL),
            'changefreq' => 'weekly',
            'priority' => '0.8',
        ];

        $urls[] = [
            'loc' => $this->generateUrl('landing_for_brands', ['_locale' => 'ru'], UrlGeneratorInterface::ABSOLUTE_URL),
            'changefreq' => 'weekly',
            'priority' => '0.8',
        ];

        // Лендинг комиссий 301-ит на статью блога, как только она опубликована — тогда из sitemap он уходит
        if (!$articleRepo->findOnePublishedBySlug('komissii-marketpleysov-2026', 'ru')) {
            $urls[] = [
                'loc' => $this->generateUrl('landing_marketplace_fees', ['_locale' => 'ru'], UrlGeneratorInterface::ABSOLUTE_URL),
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ];
        }

        $urls[] = [
            'loc' => $this->generateUrl('blog_index', ['_locale' => 'ru'], UrlGeneratorInterface::ABSOLUTE_URL),
            'changefreq' => 'weekly',
            'priority' => '0.8',
        ];

        $urls[] = [
            'loc' => $this->generateUrl('brand_cities', ['_locale' => 'ru'], UrlGeneratorInterface::ABSOLUTE_URL),
            'changefreq' => 'weekly',
            'priority' => '0.7',
        ];

        $cityNames = $repo->createQueryBuilder('b')
            ->select('DISTINCT b.city')
            ->where('b.status = :status')
            ->andWhere('b.city IS NOT NULL')
            ->andWhere('b.city != \'\'')
            ->setParameter('status', 'active')
            ->getQuery()
            ->getSingleColumnResult();

        foreach ($cityNames as $cityName) {
            $urls[] = [
                'loc' => $this->generateUrl('brand_city', [
                    '_locale' => 'ru',
                    'slug' => $citySlugger->slugify($cityName),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ];
        }

        foreach ($articleRepo->findPublished('ru', 500) as $article) {
            $urls[] = [
                'loc' => $this->generateUrl('blog_show', [
                    '_locale' => 'ru',
                    'slug' => $article->getSlug(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'changefreq' => 'monthly',
                'priority' => '0.7',
                'lastmod' => $article->getUpdatedAt()?->format('Y-m-d'),
            ];
        }

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