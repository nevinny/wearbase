<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BlogController extends AbstractController
{
    private const PER_PAGE = 12;

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
    public function show(string $slug, Request $request, ArticleRepository $articles): Response
    {
        $article = $articles->findOnePublishedBySlug($slug, $request->getLocale());
        if (!$article) {
            throw $this->createNotFoundException('Статья не найдена');
        }

        return $this->render('tailwind/blog/show.html.twig', [
            'article' => $article,
        ]);
    }
}
