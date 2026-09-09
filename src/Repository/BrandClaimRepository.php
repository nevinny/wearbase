<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\BrandClaim;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrandClaim>
 */
class BrandClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrandClaim::class);
    }

    public function findPendingByBrandAndUser(Brand $brand, User $user): ?BrandClaim
    {
        return $this->createQueryBuilder('c')
            ->where('c.brand = :brand')
            ->andWhere('c.user = :user')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('brand', $brand)
            ->setParameter('user', $user)
            ->setParameter('statuses', [BrandClaim::STATUS_PENDING, BrandClaim::STATUS_EMAIL_VERIFIED])
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return BrandClaim[] */
    public function findPending(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status IN (:statuses)')
            ->setParameter('statuses', [BrandClaim::STATUS_PENDING, BrandClaim::STATUS_EMAIL_VERIFIED])
            ->orderBy('c.emailDomainMatch', 'DESC') // email-verified first
            ->addOrderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** pending/email_verified старше $cutoff — админ не решил вовремя (app:moderation:timeouts). @return BrandClaim[] */
    public function findOverduePending(\DateTimeInterface $cutoff): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status IN (:statuses)')
            ->andWhere('c.createdAt < :cutoff')
            ->setParameter('statuses', [BrandClaim::STATUS_PENDING, BrandClaim::STATUS_EMAIL_VERIFIED])
            ->setParameter('cutoff', $cutoff)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Снимок очереди заявок на владение для админ-дашборда: сколько ждут решения
     * (pending/email_verified) и когда создана самая старая — один COUNT вместо
     * загрузки сущностей (findPending() тянет их пачкой, для дашборда лишнее).
     *
     * @return array{pending:int, oldestAt:?\DateTimeImmutable}
     */
    public function dashboardSnapshot(): array
    {
        $row = $this->createQueryBuilder('c')
            ->select('COUNT(c.id) AS pending', 'MIN(c.createdAt) AS oldestAt')
            ->where('c.status IN (:statuses)')
            ->setParameter('statuses', [BrandClaim::STATUS_PENDING, BrandClaim::STATUS_EMAIL_VERIFIED])
            ->getQuery()
            ->getSingleResult();

        // MIN() de datetime: Doctrine hidrata согласно типу поля на одних платформах (объект),
        // на других отдаёт сырую строку — обрабатываем оба случая.
        $oldestRaw = $row['oldestAt'];
        $oldestAt = match (true) {
            $oldestRaw instanceof \DateTimeImmutable => $oldestRaw,
            $oldestRaw instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($oldestRaw),
            $oldestRaw !== null => new \DateTimeImmutable((string) $oldestRaw),
            default => null,
        };

        return [
            'pending'  => (int) $row['pending'],
            'oldestAt' => $oldestAt,
        ];
    }
}
