<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WardrobeItemDraft;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WardrobeItemDraft>
 */
class WardrobeItemDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WardrobeItemDraft::class);
    }

    /**
     * @return WardrobeItemDraft[]
     */
    public function findPending(int $limit, ?string $batchId = null): array
    {
        $query = $this->createQueryBuilder('d')
            ->andWhere('d.status = :status')
            ->setParameter('status', WardrobeItemDraft::STATUS_PENDING)
            ->orderBy('d.id', 'ASC')
            ->setMaxResults($limit);
        if ($batchId !== null) {
            $query->andWhere('d.batchId = :batchId')->setParameter('batchId', $batchId);
        }

        return $query->getQuery()
            ->getResult();
    }

    public function findDuplicate(User $subject, string $contentHash): ?WardrobeItemDraft
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.user = :subject')
            ->andWhere('d.contentHash = :hash')
            ->andWhere('d.status != :rejected')
            ->setParameter('subject', $subject)
            ->setParameter('hash', $contentHash)
            ->setParameter('rejected', WardrobeItemDraft::STATUS_REJECTED)
            ->orderBy('d.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return WardrobeItemDraft[] */
    public function claimPending(int $limit, string $workerId, ?string $batchId = null): array
    {
        $em = $this->getEntityManager();

        return $em->wrapInTransaction(function (EntityManagerInterface $em) use ($limit, $workerId, $batchId): array {
            $now = new \DateTimeImmutable();
            $builder = $this->createQueryBuilder('d')
                ->andWhere('d.status = :pending OR (d.status = :processing AND d.leaseUntil < :now)')
                ->setParameter('pending', WardrobeItemDraft::STATUS_PENDING)
                ->setParameter('processing', WardrobeItemDraft::STATUS_PROCESSING)
                ->setParameter('now', $now)
                ->orderBy('d.id', 'ASC')
                ->setMaxResults($limit);
            if ($batchId !== null) {
                $builder->andWhere('d.batchId = :batchId')->setParameter('batchId', $batchId);
            }
            $drafts = $builder->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getResult();
            foreach ($drafts as $draft) {
                $draft->claim($workerId, $now->modify('+5 minutes'));
            }
            $em->flush();

            return $drafts;
        });
    }

    /**
     * @return WardrobeItemDraft[]
     */
    public function findByBatch(User $user, string $batchId): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->andWhere('d.batchId = :batchId')
            ->setParameter('user', $user)
            ->setParameter('batchId', $batchId)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{total:int,pending:int,recognized:int,failed:int}
     */
    public function countsByBatch(User $user, string $batchId): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.status AS status', 'COUNT(d.id) AS cnt')
            ->andWhere('d.user = :user')
            ->andWhere('d.batchId = :batchId')
            ->andWhere('d.status NOT IN (:terminal)')
            ->setParameter('user', $user)
            ->setParameter('batchId', $batchId)
            ->setParameter('terminal', [WardrobeItemDraft::STATUS_ACCEPTED, WardrobeItemDraft::STATUS_REJECTED])
            ->groupBy('d.status')
            ->getQuery()
            ->getArrayResult();

        $counts = ['total' => 0, 'pending' => 0, 'recognized' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $cnt = (int) $row['cnt'];
            $counts['total'] += $cnt;
            if (array_key_exists($row['status'], $counts)) {
                $counts[$row['status']] = $cnt;
            }
        }

        return $counts;
    }
}
