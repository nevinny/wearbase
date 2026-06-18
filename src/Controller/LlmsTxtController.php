<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\BrandRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * /llms.txt — markdown-индекс сайта для AI-агентов (конвенция llms.txt).
 *
 * В отличие от sitemap.xml (исчерпывающий список всех URL) llms.txt — это
 * краткая «карта» самого ценного контента: разделы, лендинги, блог и топ
 * брендов с подробным описанием. Полный перечень брендов — в sitemap.xml.
 */
class LlmsTxtController extends AbstractController
{
    /** Сколько брендов с самым подробным описанием выводить в индекс. */
    private const BRAND_LIMIT = 60;

    /** Минимальная длина описания, чтобы бренд попал в llms.txt (тонкие заглушки не нужны). */
    private const MIN_DESC_LEN = 400;

    #[Route('/llms.txt', name: 'llms_txt')]
    public function llmsTxt(BrandRepository $repo, ArticleRepository $articleRepo, UrlGeneratorInterface $urlGenerator): Response
    {
        // <loc> всегда от канонического хоста — как в SitemapController:
        // llms.txt, запрошенный через www/dev-хост, не должен раздавать неканонические URL.
        $siteBase = parse_url((string) $this->getParameter('app.site_base_url'));
        $context  = $urlGenerator->getContext();
        $context->setScheme($siteBase['scheme'] ?? 'https');
        $context->setHost($siteBase['host'] ?? 'wearbase.ru');

        $url = fn (string $name, array $params = []) => $this->generateUrl(
            $name,
            ['_locale' => 'ru'] + $params,
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $lines = [];
        $lines[] = '# WEARBASE — каталог российских брендов одежды';
        $lines[] = '';
        $lines[] = '> WEARBASE — каталог российских брендов одежды и обуви с поддержкой международных рынков. '
            . 'Профили брендов с описанием, городом и контактами, подборки по городам, блог об индустрии моды и '
            . 'маркетплейсах. Продажи идут напрямую от брендов без комиссии платформы. Контент на русском языке.';
        $lines[] = '';

        $lines[] = '## Основные разделы';
        $lines[] = sprintf('- [Каталог брендов](%s): полный список активных российских брендов одежды', $url('brand_index'));
        $lines[] = sprintf('- [Бренды по городам](%s): подборки брендов по городам России', $url('brand_cities'));
        $lines[] = sprintf('- [Блог](%s): статьи об индустрии моды, маркетплейсах и брендах', $url('blog_index'));
        $lines[] = sprintf('- [Брендам — как разместиться](%s): условия размещения для брендов', $url('landing_for_brands'));
        $lines[] = sprintf('- [Маркетплейс без комиссии](%s): прямые продажи от бренда покупателю', $url('landing_no_marketplace'));
        $lines[] = sprintf('- [О проекте](%s)', $url('about_us'));
        $lines[] = sprintf('- [Условия возврата](%s)', $url('return_policy'));
        $lines[] = '';

        // Блог — высокоценный редакторский контент, выводим целиком.
        $articles = $articleRepo->findPublished('ru', 200);
        if ($articles) {
            $lines[] = '## Блог';
            foreach ($articles as $article) {
                $loc  = $url('blog_show', ['slug' => $article->getSlug()]);
                $desc = $this->snippet($article->getExcerpt());
                $lines[] = $desc
                    ? sprintf('- [%s](%s): %s', $article->getTitle(), $loc, $desc)
                    : sprintf('- [%s](%s)', $article->getTitle(), $loc);
            }
            $lines[] = '';
        }

        // Топ брендов по объёму описания (контент-богатые карточки).
        $brands = $repo->createQueryBuilder('b')
            ->where('b.status = :status')
            ->andWhere('b.slug IS NOT NULL')
            ->andWhere('LENGTH(b.description) >= :minLen')
            ->setParameter('status', 'active')
            ->setParameter('minLen', self::MIN_DESC_LEN)
            ->orderBy('LENGTH(b.description)', 'DESC')
            ->setMaxResults(self::BRAND_LIMIT)
            ->getQuery()
            ->getResult();

        if ($brands) {
            $lines[] = '## Бренды';
            foreach ($brands as $brand) {
                $loc  = $url('brand_show', ['slug' => $brand->getSlug()]);
                $desc = $this->snippet($brand->getMetaDescription() ?: $brand->getDescription());
                $name = $brand->getTitle() ?: $brand->getSlug();
                $lines[] = $desc
                    ? sprintf('- [%s](%s): %s', $name, $loc, $desc)
                    : sprintf('- [%s](%s)', $name, $loc);
            }
            $lines[] = '';
        }

        $lines[] = '## Полный список';
        $lines[] = sprintf('Все бренды и страницы перечислены в sitemap: %s/sitemap.xml',
            rtrim((string) $this->getParameter('app.site_base_url'), '/'));
        $lines[] = '';

        return new Response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
        ]);
    }

    /** Чистый однострочный сниппет из текста/HTML, обрезанный по границе. */
    private function snippet(?string $raw, int $max = 160): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $raw)));
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1) . '…';
    }
}
