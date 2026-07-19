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
    public function sitemap(Request $request, BrandRepository $repo, ArticleRepository $articleRepo, \App\Repository\AuthorRepository $authorRepo, \App\Repository\CityHubRepository $cityHubRepo, \App\Service\CitySlugger $citySlugger, UrlGeneratorInterface $urlGenerator): Response
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
            'loc' => $this->generateUrl('about_us', ['_locale' => 'ru'], UrlGeneratorInterface::ABSOLUTE_URL),
            'changefreq' => 'monthly',
            'priority' => '0.6',
        ];

        $urls[] = [
            'loc' => $this->generateUrl('return_policy', ['_locale' => 'ru'], UrlGeneratorInterface::ABSOLUTE_URL),
            'changefreq' => 'monthly',
            'priority' => '0.5',
        ];

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

        // Гейт индексации тонких гео-хабов (docs/seo_sitewide_backlog.md HIGH-1): в sitemap
        // попадают только города с >= MIN_INDEXABLE_BRANDS брендов ИЛИ с активным кураторским
        // CityHub (индексируется независимо от числа брендов, как и на самой странице).
        $cityCountsQb = $repo->createQueryBuilder('b')
            ->select('b.city, COUNT(b.id) as cnt')
            ->where('b.status = :status')
            ->andWhere('b.city IS NOT NULL')
            ->andWhere('b.city != \'\'')
            ->setParameter('status', 'active')
            ->groupBy('b.city');
        $repo->excludeForeignOrigin($cityCountsQb);
        $cityCounts = $cityCountsQb->getQuery()->getResult();

        foreach ($cityCounts as $cityRow) {
            $citySlug = $citySlugger->slugify($cityRow['city']);
            $isIndexable = (int) $cityRow['cnt'] >= \App\Controller\Brands\BrandsController::MIN_INDEXABLE_BRANDS
                || $cityHubRepo->findActiveBySlug($citySlug) !== null;
            if (!$isIndexable) {
                continue;
            }
            $urls[] = [
                'loc' => $this->generateUrl('brand_city', [
                    '_locale' => 'ru',
                    'slug' => $citySlug,
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ];
        }

        $urls[] = [
            'loc' => $this->generateUrl('brand_styles', ['_locale' => 'ru'], UrlGeneratorInterface::ABSOLUTE_URL),
            'changefreq' => 'weekly',
            'priority' => '0.7',
        ];

        // Стилевые хабы — гейт индексации тонких хабов (docs/seo_sitewide_backlog.md HIGH-1):
        // только стили с >= MIN_INDEXABLE_BRANDS активных брендов (пустые/тонкие не индексируем).
        $styleSlugsQb = $repo->createQueryBuilder('b')
            ->select('s.slug')
            ->join('b.styles', 's')
            ->where('b.status = :status')
            ->andWhere('s.slug IS NOT NULL')
            ->setParameter('status', 'active')
            ->groupBy('s.id')
            ->having('COUNT(DISTINCT b.id) >= :minBrands')
            ->setParameter('minBrands', \App\Controller\Brands\BrandsController::MIN_INDEXABLE_BRANDS);
        $repo->excludeForeignOrigin($styleSlugsQb);
        $styleSlugs = $styleSlugsQb->getQuery()->getSingleColumnResult();

        foreach ($styleSlugs as $styleSlug) {
            $urls[] = [
                'loc' => $this->generateUrl('brand_style', [
                    '_locale' => 'ru',
                    'slug' => $styleSlug,
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

        // Страницы авторов (E-E-A-T) — supporting-страницы
        foreach ($authorRepo->findActive() as $author) {
            $urls[] = [
                'loc' => $this->generateUrl('author_show', [
                    '_locale' => 'ru',
                    'slug' => $author->getSlug(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'changefreq' => 'monthly',
                'priority' => '0.5',
                'lastmod' => $author->getUpdatedAt()?->format('Y-m-d'),
            ];
        }

        $brands = $repo->createQueryBuilder('b')
            ->where('b.status = :status')
            ->setParameter('status', 'active')
            ->getQuery()
            ->getResult();

        foreach ($brands as $brand) {
            if ($brand->getSlug()) {
                // Приоритет по РЕАЛЬНОМУ объёму контента, а не по факту наличия строки:
                // legacy-заглушка в 17 символов — truthy, но это тонкая страница, которую
                // Google всё равно не индексирует (crawled - not indexed). Не разбазариваем
                // на неё сигнал приоритета. Заполнится через RAG-генерацию → вырастет тир.
                $descLen  = mb_strlen(strip_tags((string) $brand->getDescription()));
                $priority = $descLen >= 1000 ? '0.7' : ($descLen >= 400 ? '0.6' : '0.4');
                $urls[] = [
                    'loc' => $this->generateUrl('brand_show', [
                        '_locale' => 'ru',
                        'slug' => $brand->getSlug()
                    ], UrlGeneratorInterface::ABSOLUTE_URL),
                    'changefreq' => 'weekly',
                    'priority' => $priority,
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

    /**
     * Отдельный sitemap только со статьями блога — для точечного сабмита в GSC
     * (диагностика индексации блога отдельно от каталога брендов).
     * Дублирует URL из /sitemap.xml — это ожидаемо и безвредно.
     */
    #[Route('/sitemap-blog.xml', name: 'sitemap_blog_xml', defaults: ['_format' => 'xml'])]
    public function sitemapBlog(ArticleRepository $articleRepo, UrlGeneratorInterface $urlGenerator): Response
    {
        $siteBase = parse_url((string) $this->getParameter('app.site_base_url'));
        $context  = $urlGenerator->getContext();
        $context->setScheme($siteBase['scheme'] ?? 'https');
        $context->setHost($siteBase['host'] ?? 'wearbase.ru');

        $urls = [];
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

        $xml = $this->renderView('sitemap/xml.html.twig', [
            'urls' => $urls,
        ]);

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }
}