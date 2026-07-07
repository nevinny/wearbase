<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Дзен для сайтов": Дзен сам поллит эту ленту и заносит материалы привязанного
 * канала в черновики — замыкает блог→Дзен без ручного копирования текста.
 * `native-draft` — публикацию всё равно подтверждает человек (правило очерёдности
 * «блог→индексация→Дзен» и добавление обложки/CTA/UTM по методологии остаются
 * ручными, см. docs/seo_publishing_strategy.md, docs/dzen_seo_methodology.md).
 */
class DzenFeedController extends AbstractController
{
    // Лента — скользящее окно последних публикаций, а не архив: Дзен ждёт актуальные
    // материалы, а не разовый бэклог (см. docs/seo_publishing_strategy.md).
    private const MAX_ITEMS = 30;

    // Буфер после подтверждённой индексации в Google (article.indexed_at), прежде чем
    // статья попадёт в фид: копия на Дзене (сильный домен) не должна появиться раньше,
    // чем Яндекс/Google закрепят wearbase.ru как первоисточник. indexed_at — единственный
    // exhaustive per-URL сигнал индексации, который у нас есть (Yandex Webmaster API
    // такого per-URL инспектора не даёт, только несплошную выборку yandex_index_status).
    private const MIN_INDEXED_DAYS = 3;

    #[Route('/rss/dzen.xml', name: 'dzen_feed', defaults: ['_format' => 'xml'])]
    public function feed(ArticleRepository $articleRepo, UrlGeneratorInterface $urlGenerator): Response
    {
        $siteBase = parse_url((string) $this->getParameter('app.site_base_url'));
        $context  = $urlGenerator->getContext();
        $context->setScheme($siteBase['scheme'] ?? 'https');
        $context->setHost($siteBase['host'] ?? 'wearbase.ru');

        $items = [];
        foreach ($articleRepo->findIndexedForSyndication('ru', self::MAX_ITEMS, self::MIN_INDEXED_DAYS) as $article) {
            $link = $this->generateUrl('blog_show', [
                '_locale' => 'ru',
                'slug' => $article->getSlug(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            // Дзен показывает title из content:encoded, а не из <title> — в БД он
            // хранится без H1 (заголовок рендерит сам Twig-шаблон блога), поэтому
            // возвращаем H1 обратно только для фида.
            $content = preg_replace('#<script type="application/ld\+json">.*?</script>#s', '', $article->getContent());
            $content = '<h1>' . htmlspecialchars($article->getTitle(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</h1>\n" . $content
                . "\n<p><em>Источник: <a href=\"{$link}\">wearbase.ru</a></em></p>";

            $items[] = [
                'title'   => $article->getTitle(),
                'link'    => $link,
                'pubDate' => $article->getPublishedAt()->format(\DATE_RSS),
                'excerpt' => $article->getExcerpt(),
                'content' => $content,
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
