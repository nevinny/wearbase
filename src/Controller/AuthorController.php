<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\AuthorRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthorController extends AbstractController
{
    #[Route('/{_locale}/author', name: 'author_index', requirements: ['_locale' => 'en|ru'], defaults: ['_locale' => 'ru'])]
    public function index(AuthorRepository $authors): Response
    {
        return $this->render('tailwind/author/index.html.twig', [
            'authors' => $authors->findActive(),
        ]);
    }

    #[Route('/{_locale}/author/{slug}', name: 'author_show', requirements: ['_locale' => 'en|ru', 'slug' => '[a-z0-9-]+'], defaults: ['_locale' => 'ru'])]
    public function show(string $slug, AuthorRepository $authors, ArticleRepository $articles): Response
    {
        $author = $authors->findOneActiveBySlug($slug);
        if (!$author) {
            throw $this->createNotFoundException('Автор не найден');
        }

        return $this->render('tailwind/author/show.html.twig', [
            'author'   => $author,
            // Контент только ru (не-ru локали — noindex-fallback на ru), список берём по ru
            'articles' => $articles->findPublishedByAuthor($author->getId(), 'ru'),
        ]);
    }
}
