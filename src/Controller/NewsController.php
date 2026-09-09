<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\NewsItemRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Публичная лента новостей /news и страница /news/{slug}.
 * Индексируемые (без noindex), self-canonical; блок «По материалам …»
 * с постоянным dofollow обязателен (_docs/news-sources-tos.md §2.2).
 */
final class NewsController extends AbstractController
{
    private const PER_PAGE = 12;

    public function __construct(private readonly NewsItemRepository $items)
    {
    }

    #[Route('/news', name: 'news_index')]
    public function index(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));

        $total = $this->items->countPublished();
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);

        return $this->render('tailwind/news/index.html.twig', [
            'items' => $this->items->findPublished(self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    #[Route('/news/{slug}', name: 'news_show', requirements: ['slug' => '[a-z0-9-]+'])]
    public function show(string $slug): Response
    {
        $item = $this->items->findOnePublishedBySlug($slug);
        if ($item === null) {
            throw $this->createNotFoundException('Новость не найдена');
        }

        return $this->render('tailwind/news/show.html.twig', [
            'item' => $item,
        ]);
    }
}
