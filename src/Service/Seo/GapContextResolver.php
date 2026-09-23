<?php

declare(strict_types=1);

namespace App\Service\Seo;

use App\Entity\CompetitorArticle;
use App\Entity\SeoCompetitorScan;
use App\Service\NearDuplicateDetector;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Общая точка правды для --gap-context=<seo_competitor_scan id> (docs/seo_competitor_content.md):
 * резолв id → (scan, темы конкурентов) с фейл-лаудом на неизвестный id/пустой gap_summary,
 * и anti-duplicate гейт против article-конкурентов скана. Вынесено из
 * GenerateListicleCommand/ReplaceListicleCommand/SeoGuideCommand — было продублировано
 * почти дословно (см. код-ревью 2026-09-23) в нарушение «не плодить копии quality-gate».
 */
final class GapContextResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NearDuplicateDetector $nearDup,
    ) {
    }

    /**
     * @return array{scan: ?SeoCompetitorScan, topics: ?string, error: ?string} error!==null → вызывающий
     *         обязан io->error($error) и вернуть Command::FAILURE, не продолжая генерацию.
     */
    public function resolve(?string $gapContextId): array
    {
        if ($gapContextId === null) {
            return ['scan' => null, 'topics' => null, 'error' => null];
        }

        $scan = $this->em->find(SeoCompetitorScan::class, (int) $gapContextId);
        if ($scan === null) {
            return ['scan' => null, 'topics' => null, 'error' => "seo_competitor_scan ID {$gapContextId} не найден."];
        }

        $topics = $scan->getGapSummary();
        if ($topics === null || trim($topics) === '') {
            return [
                'scan'   => null,
                'topics' => null,
                'error'  => sprintf(
                    'seo_competitor_scan ID %d: gap_summary пуст (status=%s) — нечего подмешивать. '
                        . 'Запустите без --gap-context либо дождитесь анализа (app:seo:competitor-scan).',
                    $scan->getId(),
                    $scan->getStatus(),
                ),
            ];
        }

        return ['scan' => $scan, 'topics' => $topics, 'error' => null];
    }

    /**
     * Anti-duplicate (docs/seo_competitor_content.md, «Anti-duplicate»): сгенерированный
     * текст не должен совпадать со статьёй конкурента, у которого позаимствовали темы —
     * иначе это уже не «раскрыть тему», а пересказ. Сверяем ТОЛЬКО article-конкурентов
     * из serp_results скана (у остальных competitor_article_id пуст).
     *
     * @return string[]
     */
    public function nearDuplicateIssues(string $body, ?SeoCompetitorScan $gapScan): array
    {
        if ($gapScan === null) {
            return [];
        }

        $issues = [];
        $bodyShingles = $this->nearDup->shingles($body);
        foreach ($gapScan->getSerpResults() as $r) {
            $articleId = $r['competitor_article_id'] ?? null;
            if ($articleId === null) {
                continue;
            }
            $article = $this->em->find(CompetitorArticle::class, (int) $articleId);
            if ($article === null || $article->getContent() === null || trim($article->getContent()) === '') {
                continue;
            }
            $sim = $this->nearDup->jaccard($bodyShingles, $this->nearDup->shingles($article->getContent()));
            if ($sim >= NearDuplicateDetector::DROP_THRESHOLD) {
                $issues[] = sprintf(
                    'near-duplicate с конкурентом %s (jaccard=%.2f ≥ %.2f) — перепиши своими словами',
                    $article->getDomain(),
                    $sim,
                    NearDuplicateDetector::DROP_THRESHOLD,
                );
            }
        }

        return $issues;
    }
}
