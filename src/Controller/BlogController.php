<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\BrandRepository;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BlogController extends AbstractController
{
    private const PER_PAGE = 12;
    private const SIMILAR_LIMIT = 5;

    #[Route('/{_locale}/blog', name: 'blog_index', requirements: ['_locale' => 'en|ru'], defaults: ['_locale' => 'ru'])]
    public function index(Request $request, ArticleRepository $articles): Response
    {
        $locale = $request->getLocale();
        $page = max(1, $request->query->getInt('page', 1));

        $total = $articles->countPublished($locale);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);

        return $this->render('tailwind/blog/index.html.twig', [
            'articles'   => $articles->findPublished($locale, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'page'       => $page,
            'totalPages' => $totalPages,
        ]);
    }

    #[Route('/{_locale}/blog/{slug}', name: 'blog_show', requirements: ['_locale' => 'en|ru', 'slug' => '[a-z0-9-]+'], defaults: ['_locale' => 'ru'])]
    public function show(string $slug, Request $request, ArticleRepository $articles, BrandRepository $brands): Response
    {
        $locale = $request->getLocale();
        $article = $articles->findOnePublishedBySlug($slug, $locale);
        if (!$article) {
            throw $this->createNotFoundException('Статья не найдена');
        }

        // Бренды, упомянутые в тексте статьи (ссылки вида /brands/{slug}) — только активные.
        // M2M Article↔Brand не вводим (см. docs/seo_sitewide_backlog.md HIGH-4): это
        // отдельное решение владельца продукта, здесь — минимальный парсинг существующих ссылок.
        $articleBrands = [];
        if (preg_match_all('~href="[^"]*/brands/([a-z0-9]+(?:-[a-z0-9]+)*)[^"]*"~i', $article->getContent(), $matches)) {
            $brandSlugs = array_values(array_unique($matches[1]));
            $articleBrands = $brands->findBy(['slug' => $brandSlugs, 'status' => Statuses::Active]);
        }

        return $this->render('tailwind/blog/show.html.twig', [
            'article' => $article,
            'similarArticles' => $articles->findSimilar($article, $locale, self::SIMILAR_LIMIT),
            'articleBrands' => $articleBrands,
        ]);
    }
}
