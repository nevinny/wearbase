<?php

namespace App\Controller;

use App\Entity\Brand;
use App\Repository\ArticleDistributionRepository;
use App\Repository\BrandRepository;
use App\Service\Social\BrandGalleryImages;
use Doctrine\DBAL\Connection;
use Nevinny\AdminCoreBundle\Enum\Statuses;
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
 * docs/dzen_seo_methodology.md). Разметка item сведена к эталонной ленте
 * getmodel.com/dzen.xml — см. docs/dzen_rss_audit.md.
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

    // Спека Дзена на обложку/картинки: ширина ≥700px, форматы JPEG/PNG/GIF — иначе
    // материал публикуется без изображения (webp в brand_image поэтому пропускаем).
    private const COVER_MIN_WIDTH = 700;
    private const COVER_MIME = ['image/jpeg', 'image/png', 'image/gif'];

    #[Route('/rss/dzen.xml', name: 'dzen_feed', defaults: ['_format' => 'xml'])]
    public function feed(
        ArticleDistributionRepository $distributionRepo,
        Connection $db,
        UrlGeneratorInterface $urlGenerator,
        BrandRepository $brandRepo,
        BrandGalleryImages $gallery,
    ): Response {
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

        $brandsBySlug = $this->loadBrands($selected, $brandRepo);
        $siteBaseUrl  = rtrim((string) $this->getParameter('app.site_base_url'), '/');

        $items = [];
        foreach ($selected as $distribution) {
            $article = $distribution->getArticle();

            $link = $this->generateUrl('blog_show', [
                '_locale' => 'ru',
                'slug' => $article->getSlug(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            $title = $distribution->getTitle() ?? $article->getTitle();

            // H1 в content:encoded не дублируем: с явной category=format-article Дзен берёт
            // заголовок из <title> (дубль внутри текста спека требует только для постов),
            // как и в эталонной ленте.
            $content = preg_replace('#<script type="application/ld\+json">.*?</script>#s', '', $distribution->getContent());
            $cover   = $this->pickCover($content, $brandsBySlug, $gallery, $siteBaseUrl);

            $content = ($cover !== null ? '<figure><img src="' . $cover['url'] . "\"></figure>\n" : '')
                . $this->toDzenHtml($content)
                . ($cover !== null
                    ? "\n<p><i>Фото на обложке: " . htmlspecialchars($cover['credit'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</i></p>'
                    : '')
                . "\n<p><i>Источник: <a href=\"{$link}\">wearbase.ru</a></i></p>";

            $items[] = [
                'title'   => $title,
                'link'    => $link,
                // Переизданная статья (app:seo:republish-blog) должна выглядеть для Дзена
                // свежей: updatedAt > publishedAt только после переиздания (Created-трейт
                // бампает updatedAt лишь на PrePersist, а publishedAt дрипа — в будущем).
                'pubDate' => \DateTimeImmutable::createFromInterface(max($article->getPublishedAt(), $article->getUpdatedAt()))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('D, d M Y H:i:s \G\M\T'),
                'excerpt' => $distribution->getExcerpt() ?? $article->getExcerpt(),
                'cover'   => $cover,
                'content' => $content,
            ];
        }

        $xml = $this->renderView('rss/dzen.xml.twig', [
            'siteTitle'       => 'WEARBASE',
            'siteLink'        => $this->generateUrl('blog_index', ['_locale' => 'ru'], UrlGeneratorInterface::ABSOLUTE_URL),
            'siteDescription' => 'Статьи о российских брендах одежды: подборки брендов, гайды для покупателей, комиссии маркетплейсов, продажи без посредников.',
            'items'           => $items,
        ]);

        return new Response($xml, 200, [
            'Content-Type' => 'application/rss+xml; charset=utf-8',
        ]);
    }

    /**
     * Активные бренды, на которые ссылаются тексты выбранных копий, одним запросом.
     *
     * @param list<\App\Entity\ArticleDistribution> $distributions
     * @return array<string,Brand> slug => Brand
     */
    private function loadBrands(array $distributions, BrandRepository $brandRepo): array
    {
        $slugs = [];
        foreach ($distributions as $distribution) {
            array_push($slugs, ...$this->brandSlugs($distribution->getContent()));
        }
        if ($slugs === []) {
            return [];
        }

        $bySlug = [];
        foreach ($brandRepo->findBy(['slug' => array_values(array_unique($slugs))]) as $brand) {
            if ($brand->getStatus() === Statuses::Active) {
                $bySlug[$brand->getSlug()] = $brand;
            }
        }

        return $bySlug;
    }

    /** @return list<string> slug'и брендов в порядке первого упоминания в тексте */
    private function brandSlugs(string $html): array
    {
        preg_match_all('~/brands/([a-z0-9_-]+)~', $html, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Обложка материала: enclosure + <figure> в начале текста (как в эталонной ленте).
     * Своих картинок у статей нет, поэтому берём реальное фото первого упомянутого в
     * тексте бренда — порядок кадров из BrandGalleryImages (вещь на человеке первой),
     * первый кадр, проходящий спеку Дзена по формату и ширине. Нет такого — без обложки.
     *
     * @param array<string,Brand> $brandsBySlug
     * @return array{url:string,type:string,credit:string}|null
     */
    private function pickCover(string $html, array $brandsBySlug, BrandGalleryImages $gallery, string $siteBaseUrl): ?array
    {
        $publicDir = $this->getParameter('kernel.project_dir') . '/public_html';

        foreach ($this->brandSlugs($html) as $slug) {
            $brand = $brandsBySlug[$slug] ?? null;
            if ($brand === null) {
                continue;
            }

            foreach ($gallery->paths($brand) as $path) {
                $size = @getimagesize($publicDir . $path);
                if ($size !== false && $size[0] >= self::COVER_MIN_WIDTH && in_array($size['mime'], self::COVER_MIME, true)) {
                    return ['url' => $siteBaseUrl . $path, 'type' => $size['mime'], 'credit' => (string) $brand->getTitle()];
                }
            }
        }

        return null;
    }

    // Белый список Дзена (dzen.ru/help/ru/website/rss-modify.html): любой другой тег
    // разворачивается (unwrap), а не вырезается вместе с текстом.
    private const ALLOWED_TAGS = [
        'p', 'a', 'b', 'i', 'u', 's', 'h1', 'h2', 'h3', 'h4',
        'blockquote', 'ul', 'ol', 'li', 'figure', 'img', 'video',
    ];

    // Атрибуты, которые Дзен явно поддерживает — единственное исключение из
    // «никаких атрибутов» (id/class/style и т.п. со всех тегов снимаются).
    private const ALLOWED_ATTRS = [
        'a'     => ['href'],
        'img'   => ['src', 'alt'],
        'video' => ['src'],
    ];

    /**
     * Приводит HTML статьи к подмножеству, которое обрабатывает Дзен
     * (dzen.ru/help/ru/website/rss-modify.html): только p, a, b, i, u, s, h1-h4,
     * blockquote, ul/ol>li, figure, img, video — БЕЗ атрибутов, кроме href/src/alt.
     * Таблицы Дзен выбрасывает, поэтому конвертируем их в список: первая ячейка
     * строки — <b>, остальные — «заголовок: значение». Теги вне белого списка
     * (например, <span>, <h2 id="...">) разворачиваются — их содержимое остаётся,
     * сам тег и его атрибуты пропадают. Блоговый HTML не трогаем — трансформ
     * только для фида.
     */
    private function toDzenHtml(string $html): string
    {
        $html = str_replace(
            ['<strong>', '</strong>', '<em>', '</em>'],
            ['<b>', '</b>', '<i>', '</i>'],
            $html,
        );
        $html = (string) preg_replace('#<hr\s*/?>#', '', $html);

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

        $this->sanitizeDzenNode($dom->getElementsByTagName('body')->item(0));

        $out = '';
        foreach ($dom->getElementsByTagName('body')->item(0)->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    /**
     * Рекурсивно (снизу вверх — сперва потомки) приводит поддерево к белому списку
     * Дзена: разворачивает не входящие в ALLOWED_TAGS теги (span, div, section, h5...)
     * и снимает со всех оставшихся тегов атрибуты, не перечисленные в ALLOWED_ATTRS.
     */
    private function sanitizeDzenNode(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $this->sanitizeDzenNode($child);

            $tag = strtolower($child->tagName);

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->unwrapDzenNode($child);
                continue;
            }

            $keep = self::ALLOWED_ATTRS[$tag] ?? [];
            foreach (iterator_to_array($child->attributes) as $attr) {
                if (!in_array($attr->nodeName, $keep, true)) {
                    $child->removeAttribute($attr->nodeName);
                }
            }
        }
    }

    /** Заменяет узел его содержимым (детьми), сам тег и атрибуты пропадают. */
    private function unwrapDzenNode(\DOMElement $node): void
    {
        $parent = $node->parentNode;
        foreach (iterator_to_array($node->childNodes) as $child) {
            $parent->insertBefore($child, $node);
        }
        $parent->removeChild($node);
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
