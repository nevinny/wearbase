<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WardrobeItemPhoto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WardrobeItemPhoto> */
class WardrobeItemPhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, WardrobeItemPhoto::class); }

    /**
     * Кандидаты на пере-ориентацию после фикса санитайзера: EXIF со сохранённых
     * файлов уже срезан, потерянный поворот программно не отличить — поэтому
     * множество задаёт оператор явным списком id и/или окном создания записей
     * (известный диапазон пострадавших: id ~51–55).
     *
     * @param list<int>|null $ids
     * @return list<WardrobeItemPhoto>
     */
    public function findReorientCandidates(?array $ids, ?\DateTimeImmutable $since, ?\DateTimeImmutable $until): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.deletedAt IS NULL')
            ->orderBy('p.id', 'ASC');
        if ($ids !== null) {
            $qb->andWhere('p.id IN (:ids)')->setParameter('ids', $ids);
        }
        if ($since !== null) {
            $qb->andWhere('p.createdAt >= :since')->setParameter('since', $since);
        }
        if ($until !== null) {
            $qb->andWhere('p.createdAt <= :until')->setParameter('until', $until);
        }

        return $qb->getQuery()->getResult();
    }
}
