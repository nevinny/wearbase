<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiUsageLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiUsageLog>
 */
class AiUsageLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiUsageLog::class);
    }

    /**
     * Агрегат расхода с $from по сейчас: суммарно и по фиче.
     *
     * @return array{
     *     requests:int,
     *     prompt_tokens:int,
     *     completion_tokens:int,
     *     cost_usd:float,
     *     by_feature:array<string,array{requests:int,prompt_tokens:int,completion_tokens:int,cost_usd:float}>,
     * }
     */
    public function totals(\DateTimeImmutable $from): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('l.feature AS feature')
            ->addSelect('COUNT(l.id) AS requests')
            ->addSelect('SUM(l.promptTokens) AS promptTokens')
            ->addSelect('SUM(l.completionTokens) AS completionTokens')
            ->addSelect('SUM(l.costUsd) AS costUsd')
            ->where('l.createdAt >= :from')
            ->setParameter('from', $from)
            ->groupBy('l.feature')
            ->getQuery()
            ->getArrayResult();

        $total = ['requests' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'cost_usd' => 0.0];
        $byFeature = [];

        foreach ($rows as $row) {
            $entry = [
                'requests'          => (int) $row['requests'],
                'prompt_tokens'     => (int) $row['promptTokens'],
                'completion_tokens' => (int) $row['completionTokens'],
                'cost_usd'          => (float) $row['costUsd'],
            ];
            $byFeature[(string) $row['feature']] = $entry;

            $total['requests']          += $entry['requests'];
            $total['prompt_tokens']     += $entry['prompt_tokens'];
            $total['completion_tokens'] += $entry['completion_tokens'];
            $total['cost_usd']          += $entry['cost_usd'];
        }

        return $total + ['by_feature' => $byFeature];
    }
}
