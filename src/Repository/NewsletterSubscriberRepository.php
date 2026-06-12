<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NewsletterSubscriber;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NewsletterSubscriber>
 */
class NewsletterSubscriberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsletterSubscriber::class);
    }

    /** @return NewsletterSubscriber[] подтверждённые и не отписанные */
    public function findActive(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.confirmedAt IS NOT NULL')
            ->andWhere('s.unsubscribedAt IS NULL')
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
