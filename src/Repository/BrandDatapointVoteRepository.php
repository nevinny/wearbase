<?php

namespace App\Repository;

use App\Entity\BrandDatapoint;
use App\Entity\BrandDatapointVote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrandDatapointVote>
 */
class BrandDatapointVoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrandDatapointVote::class);
    }

    public function findByVoter(BrandDatapoint $dp, string $voterHash): ?BrandDatapointVote
    {
        return $this->findOneBy(['datapoint' => $dp, 'voterHash' => $voterHash]);
    }

    /** Суммы весов по типам голосов: ['confirm' => Σweight, 'reject' => Σweight]. */
    public function sumWeights(BrandDatapoint $dp): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('v.vote, SUM(v.weight) AS w')
            ->where('v.datapoint = :dp')
            ->setParameter('dp', $dp)
            ->groupBy('v.vote')
            ->getQuery()
            ->getArrayResult();

        $out = ['confirm' => 0, 'reject' => 0];
        foreach ($rows as $row) {
            $out[$row['vote']] = (int) $row['w'];
        }

        return $out;
    }
}
