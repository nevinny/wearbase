<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Дзен для сайтов": Дзен сам поллит эту ленту (каждые 2-5 мин) и публикует
 * материалы от привязанного канала — единственный способ замкнуть блог→Дзен
 * без ручной публикации. См. docs/seo_publishing_strategy.md.
 */
class DzenFeedController extends AbstractController
{
    // Дзен не индексирует материалы старше 8 дней — лента не архив, а скользящее окно.
    private const MAX_ITEMS = 30;

    #[Route('/rss/dzen.xml', name: 'dzen_feed', defaults: ['_format' => 'xml'])]
    public function feed(ArticleRepository $articleRepo, UrlGeneratorInterface $urlGenerator): Response
    {
        $siteBase = parse_url((string) $this->getParameter('app.site_base_url'));
        $context  = $urlGenerator->getContext();
        $context->setScheme($siteBase['scheme'] ?? 'https');
        $context->setHost($siteBase['host'] ?? 'wearbase.ru');

        $items = [];
        foreach ($articleRepo->findPublished('ru', self::MAX_ITEMS) as $article) {
            $items[] = [
                'title'   => $article->getTitle(),
                'link'    => $this->generateUrl('blog_show', [
                    '_locale' => 'ru',
                    'slug' => $article->getSlug(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'pubDate' => $article->getPublishedAt()?->format(\DATE_RSS) ?? $article->getCreatedAt()->format(\DATE_RSS),
                'excerpt' => $article->getExcerpt(),
                'content' => $article->getContent(),
                'author'  => $article->getAuthor()?->getName(),
            ];
        }

        $xml = $this->renderView('rss/dzen.xml.twig', [
            'siteTitle' => 'WEARBASE — блог о российских брендах одежды',
            'siteLink'  => $this->generateUrl('blog_index', ['_locale' => 'ru'], UrlGeneratorInterface::ABSOLUTE_URL),
            'items'     => $items,
        ]);

        return new Response($xml, 200, [
            'Content-Type' => 'application/rss+xml; charset=utf-8',
        ]);
    }
}
