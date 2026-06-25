<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Brand;
use App\Entity\BrandSourceUrl;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Единый источник URL-кандидатов логотипа бренда: собирает страницы
 * (own_site → website-ссылка → marketplace), скрейпит их и извлекает кандидатов
 * через LogoExtractor. Используется и стадией конвейера (FetchBrandLogoCommand),
 * и инлайн-пикером оператора на карточке бренда (BrandsController).
 *
 * brand_source_url — Mac-only таблица конвейера (на прод не уезжает), поэтому
 * brand.links (website/marketplace) даёт fallback-источник на проде.
 */
final class LogoCandidateService
{
    private const MAX_PAGES = 4;
    private const MAX_CANDIDATES = 12;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WebScraperService $scraper,
        private readonly LogoExtractor $extractor,
    ) {
    }

    /**
     * URL-кандидаты страниц с логотипом в порядке приоритета: own_site → website
     * → marketplace. Дедуп с сохранением порядка, cap MAX_PAGES.
     *
     * @return list<string>
     */
    public function candidatePages(Brand $brand): array
    {
        $urls = [];

        $bySource = fn (string $type): array => $this->em->getRepository(BrandSourceUrl::class)->findBy(
            ['brand' => $brand, 'sourceType' => $type, 'status' => BrandSourceUrl::STATUS_FETCHED],
            ['relevanceScore' => 'DESC'],
            self::MAX_PAGES,
        );

        foreach ($bySource(BrandSourceUrl::TYPE_OWN_SITE) as $u) {
            $urls[] = $u->getUrl();
        }

        foreach ($brand->getLinks() as $link) {
            $type = $link->getLinkType();
            if (($type === 'website' || $type === 'marketplace') && $link->getLinkUrl()) {
                $urls[] = $link->getLinkUrl();
            }
        }

        foreach ($bySource(BrandSourceUrl::TYPE_MARKETPLACE) as $u) {
            $urls[] = $u->getUrl();
        }

        return array_slice(array_values(array_unique($urls)), 0, self::MAX_PAGES);
    }

    /**
     * Перебирает страницы бренда, извлекает кандидатов логотипа, дедупит по URL
     * (макс. score), сортирует по score DESC. Без скачивания — только URL+метаданные;
     * валидация/скачивание на стороне LogoFetcher.
     *
     * @return list<array{url:string, score:int, source:string, favicon:bool}>
     */
    public function listCandidates(Brand $brand): array
    {
        $byUrl = [];

        foreach ($this->candidatePages($brand) as $page) {
            $html = $this->scraper->fetch($page)['html'] ?? '';
            if ($html === '') {
                continue;
            }
            foreach ($this->extractor->extract($html, $page) as $cand) {
                $url = $cand['url'];
                if (!isset($byUrl[$url]) || $cand['score'] > $byUrl[$url]['score']) {
                    $byUrl[$url] = $cand;
                }
            }
        }

        $out = array_values($byUrl);
        usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($out, 0, self::MAX_CANDIDATES);
    }
}
