<?php

namespace App\Controller;

use App\Repository\ArticleDistributionRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Дзен для сайтов": Дзен сам поллит эту ленту и заносит материалы привязанного
 * канала в черновики — замыкает блог→Дзен без ручного копирования текста.
 * Отдаёт текущую версию `article_distribution` (platform=dzen) — готовый под Дзен
 * текст, другая персона, не дубль блога (привязывается `app:seo:attach-distribution
 * dzen`), НЕ `article.content` — статьи без привязанной копии в фид не попадают.
 * `native-draft` — публикацию всё равно подтверждает человек (добавление обложки/
 * CTA/UTM по методологии остаются ручными, см. docs/seo_publishing_strategy.md,
 * docs/dzen_seo_methodology.md).
 */
class DzenFeedController extends AbstractController
{
    private const PLATFORM = 'dzen';

    // Лента — скользящее окно последних публикаций, а не архив: Дзен ждёт актуальные
    // материалы, а не разовый бэклог (см. docs/seo_publishing_strategy.md).
    private const MAX_ITEMS = 30;

    // Спека Дзена: при первом подключении лента должна содержать минимум 10 материалов
    // (dzen.ru/help/ru/website/rss-modify.html). Если проиндексированных Яндексом статей
    // меньше — добираем последними опубликованными с Дзен-копией без гейта индексации.
    private const MIN_ITEMS = 10;

    // Из скольких последних опубликованных статей с привязанной Дзен-копией выбираем
    // подмножество, уже подтверждённое в поиске Яндекса (см. fetchYandexIndexedSlugs) —
    // с запасом над MAX_ITEMS, т.к. часть кандидатов ещё не проиндексирована.
    private const CANDIDATE_POOL = 200;

    #[Route('/rss/dzen.xml', name: 'dzen_feed', defaults: ['_format' => 'xml'])]
    public function feed(ArticleDistributionRepository $distributionRepo, Connection $db, UrlGeneratorInterface $urlGenerator): Response
    {
        $siteBase = parse_url((string) $this->getParameter('app.site_base_url'));
        $context  = $urlGenerator->getContext();
        $context->setScheme($siteBase['scheme'] ?? 'https');
        $context->setHost($siteBase['host'] ?? 'wearbase.ru');

        $indexedSlugs = $this->fetchYandexIndexedSlugs($db);

        $candidates = $distributionRepo->findCurrentForPlatform(self::PLATFORM, 'ru', self::CANDIDATE_POOL);

        $selected = array_slice(
            array_filter($candidates, static fn($d) => isset($indexedSlugs[$d->getArticle()->getSlug()])),
            0,
            self::MAX_ITEMS,
        );
        foreach ($candidates as $distribution) {
            if (count($selected) >= self::MIN_ITEMS) {
                break;
            }
            if (!in_array($distribution, $selected, true)) {
                $selected[] = $distribution;
            }
        }

        $items = [];
        foreach ($selected as $distribution) {
            $article = $distribution->getArticle();

            $link = $this->generateUrl('blog_show', [
                '_locale' => 'ru',
                'slug' => $article->getSlug(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            $title = $distribution->getTitle() ?? $article->getTitle();

            // Дзен показывает title из content:encoded, а не из <title> — в Дзен-копии он
            // хранится без H1 (парсер выводит его отдельно как title), поэтому возвращаем
            // H1 обратно только для фида.
            $content = preg_replace('#<script type="application/ld\+json">.*?</script>#s', '', $distribution->getContent());
            $content = '<h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</h1>\n" . $this->toDzenHtml($content)
                . "\n<p><i>Источник: <a href=\"{$link}\">wearbase.ru</a></i></p>";

            $items[] = [
                'title'   => $title,
                'link'    => $link,
                'pubDate' => $article->getPublishedAt()->format(\DATE_RSS),
                'excerpt' => $distribution->getExcerpt() ?? $article->getExcerpt(),
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

    /**
     * Приводит HTML статьи к подмножеству, которое обрабатывает Дзен
     * (dzen.ru/help/ru/website/rss-modify.html): только p, a, b, i, u, s, h1-h4,
     * blockquote, ul/ol>li. Таблицы Дзен выбрасывает, поэтому конвертируем их в
     * список: первая ячейка строки — <b>, остальные — «заголовок: значение».
     * Блоговый HTML не трогаем — трансформ только для фида.
     */
    private function toDzenHtml(string $html): string
    {
        $html = str_replace(
            ['<strong>', '</strong>', '<em>', '</em>'],
            ['<b>', '</b>', '<i>', '</i>'],
            $html,
        );
        $html = (string) preg_replace('#<hr\s*/?>#', '', $html);

        if (!str_contains($html, '<table')) {
            return $html;
        }

        $dom = new \DOMDocument();
        $dom->loadHTML(
            '<?xml encoding="utf-8"?><body>' . $html . '</body>',
            LIBXML_NOERROR | LIBXML_NONET,
        );

        foreach (iterator_to_array($dom->getElementsByTagName('table')) as $table) {
            $headers = [];
            foreach ($table->getElementsByTagName('th') as $th) {
                $headers[] = trim($th->textContent);
            }

            $list = $dom->createElement('ul');
            foreach ($table->getElementsByTagName('tr') as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $cell) {
                    if ($cell->nodeName === 'td') {
                        $cells[] = $cell;
                    }
                }
                if ($cells === []) {
                    continue; // строка заголовков
                }

                $li = $dom->createElement('li');
                $b  = $dom->createElement('b');
                foreach (iterator_to_array($cells[0]->childNodes) as $node) {
                    $b->appendChild($node);
                }
                $li->appendChild($b);

                foreach (array_slice($cells, 1, null, true) as $idx => $cell) {
                    $label = $headers[$idx] ?? '';
                    $li->appendChild($dom->createTextNode(
                        ($idx === 1 ? ' — ' : '; ') . ($label !== '' ? $label . ': ' : ''),
                    ));
                    foreach (iterator_to_array($cell->childNodes) as $node) {
                        $li->appendChild($node);
                    }
                }
                $li->appendChild($dom->createTextNode('.'));
                $list->appendChild($li);
            }

            $table->parentNode->replaceChild($list, $table);
        }

        $out = '';
        foreach ($dom->getElementsByTagName('body')->item(0)->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    /**
     * Slug'и блог-статей, СЕЙЧАС находящихся в поиске Яндекса (`yandex_index_status`,
     * наполняется `app:yandex:sync`/`YandexWebmasterClient::urlsInSearch()`). Это
     * реальный сигнал индексации в Яндексе — Дзен внутренний продукт Яндекса и именно
     * этот поиск, а не Google, конкурирует с блогом за каноничность. Выборка не
     * исчерпывающая (сэмплы Яндекс.Вебмастера, cap 5000), поэтому это fail-closed
     * фильтр: непопавшая в сэмпл, но реально проиндексированная статья просто подождёт
     * следующего синка, а не просочится в фид раньше времени.
     *
     * @return array<string,true> slug => true
     */
    private function fetchYandexIndexedSlugs(Connection $db): array
    {
        $urls = $db->fetchFirstColumn(
            "SELECT page_url FROM yandex_index_status WHERE page_type = 'blog' AND in_search = 1",
        );

        $slugs = [];
        foreach ($urls as $url) {
            if (preg_match('~/blog/([a-z0-9-]+)~', (string) $url, $m)) {
                $slugs[$m[1]] = true;
            }
        }

        return $slugs;
    }
}
