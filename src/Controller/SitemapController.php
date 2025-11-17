<?php

namespace App\Controller;

use App\Repository\AsicModelRepository;
use App\Repository\BrandRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SitemapController extends AbstractController
{
    public function __construct(
        private BrandRepository $repository,
    )
    {}

    #[Route('/sitemap.{_format}', name: 'app_sitemap', requirements: ['_format' => 'html|xml'], format: 'xml')]
    public function sitemap(Request $request): Response
    {
        $hostname = $request->getSchemeAndHttpHost();

        $urls = []; // Массив для хранения URL-адресов карты сайта

        // Добавляйте URL-адреса вашей карты сайта динамически (например, из базы данных или маршрутов).

        // Static URLs
        $urls[] = ['loc' => $this->generateUrl('brand_index', ['_locale' => 'ru']), 'priority' => '1.00'];

        // Динамические URL-адреса из таблицы Post
        $items = $this->repository->findAll();
        foreach ($items as $item) {
            $urls[] = [
                'loc' => $this->generateUrl('brand_show', [
                    '_locale' => 'ru',
                    'slug' => $item->getSlug(),
//                    'id' => $item->getId(),
                ]),
                'priority' => '1.00',
                'lastmod' => $item->getUpdatedAt()->format('Y-m-d')
            ];
        }

        $xml = $this->renderView('sitemap/sitemap.xml.twig', [
            'urls' => $urls,
            'hostname' => $hostname
        ]);


        return new Response($xml, 200, ['Content-Type' => 'text/xml']);
    }
}
