<?php

namespace App\Repository;

use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialPost>
 */
class SocialPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialPost::class);
    }

    /**
     * Атомарно заклеймить пачку scheduled-постов, готовых к публикации (scheduled_at <= NOW())
     * на каналах указанного egress-хоста. SELECT FOR UPDATE SKIP LOCKED → UPDATE в одной
     * транзакции, чтобы параллельные тики не публиковали один пост дважды.
     * Зеркало BrandSourceUrlRepository::claimPending.
     *
     * @return SocialPost[] заклейменные (status=publishing), порядок priority DESC, scheduled_at ASC
     */
    public function claimDue(string $host, int $batch): array
    {
        $em = $this->getEntityManager();
        $conn = $em->getConnection();

        return $em->wrapInTransaction(function () use ($em, $conn, $host, $batch) {
            $ids = $conn->fetchFirstColumn(
                'SELECT p.id FROM social_post p
                   JOIN social_channel c ON c.id = p.channel_id
                  WHERE p.status = :scheduled
                    AND c.enabled = 1
                    AND c.egress_host = :host
                    AND p.scheduled_at IS NOT NULL
                    AND p.scheduled_at <= NOW()
                  ORDER BY p.priority DESC, p.scheduled_at ASC
                  LIMIT :batch
                  FOR UPDATE SKIP LOCKED',
                [
                    'scheduled' => SocialPost::STATUS_SCHEDULED,
                    'host'      => $host,
                    'batch'     => $batch,
                ],
                [
                    'scheduled' => \PDO::PARAM_STR,
                    'host'      => \PDO::PARAM_STR,
                    'batch'     => \PDO::PARAM_INT,
                ],
            );

            if ($ids === []) {
                return [];
            }

            $conn->executeStatement(
                'UPDATE social_post
                    SET status = :publishing, claimed_at = NOW()
                  WHERE id IN (:ids)',
                ['publishing' => SocialPost::STATUS_PUBLISHING, 'ids' => $ids],
                ['publishing' => \PDO::PARAM_STR, 'ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
            );

            $rows = $this->createQueryBuilder('p')
                ->where('p.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->orderBy('p.priority', 'DESC')
                ->addOrderBy('p.scheduledAt', 'ASC')
                ->getQuery()
                ->getResult();

            foreach ($rows as $row) {
                $em->refresh($row);
            }

            return $rows;
        });
    }

    /**
     * Забрать КОНКРЕТНЫЙ scheduled-пост, не дожидаясь его времени — ручной прогон
     * («опубликовать сейчас» для проверки формата). Канал/egress не проверяем: id указан
     * человеком осознанно. Пустой массив — поста нет или он уже не scheduled.
     *
     * @return list<SocialPost>
     */
    public function claimOne(int $id): array
    {
        $em = $this->getEntityManager();

        $affected = $em->getConnection()->executeStatement(
            'UPDATE social_post
                SET status = :publishing, claimed_at = NOW()
              WHERE id = :id AND status = :scheduled',
            [
                'publishing' => SocialPost::STATUS_PUBLISHING,
                'scheduled'  => SocialPost::STATUS_SCHEDULED,
                'id'         => $id,
            ],
        );

        if ($affected === 0) {
            return [];
        }

        $post = $this->find($id);
        if ($post === null) {
            return [];
        }
        $em->refresh($post);

        return [$post];
    }

    /**
     * Вернуть протухшие publishing-посты (claimed дольше $minutes) в scheduled.
     * Ловит тики, упавшие после claim, но до публикации.
     */
    public function reclaimStale(int $minutes): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE social_post
                SET status = :scheduled, claimed_at = NULL
              WHERE status = :publishing
                AND claimed_at IS NOT NULL
                AND claimed_at < (NOW() - INTERVAL :minutes MINUTE)',
            [
                'scheduled'  => SocialPost::STATUS_SCHEDULED,
                'publishing' => SocialPost::STATUS_PUBLISHING,
                'minutes'    => $minutes,
            ],
            [
                'scheduled'  => \PDO::PARAM_STR,
                'publishing' => \PDO::PARAM_STR,
                'minutes'    => \PDO::PARAM_INT,
            ],
        );
    }

    /** Сколько постов канала опубликовано за сегодня (для дрип-каденса publish-tick). */
    public function countPublishedToday(SocialChannel $channel): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.channel = :ch')
            ->andWhere('p.publishedAt >= :startOfDay')
            ->andWhere('p.status IN (:done)')
            ->setParameter('ch', $channel)
            ->setParameter('startOfDay', new \DateTime('today'))
            ->setParameter('done', [SocialPost::STATUS_PUBLISHED, SocialPost::STATUS_DONE])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Сколько медиа опубликовано на канале за последние 24ч (хард-лимит площадки, напр. IG 25/24ч). */
    public function countPublishedLast24h(SocialChannel $channel): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.channel = :ch')
            ->andWhere('p.publishedAt >= :since')
            ->andWhere('p.status IN (:done)')
            ->setParameter('ch', $channel)
            ->setParameter('since', new \DateTime('-24 hours'))
            ->setParameter('done', [SocialPost::STATUS_PUBLISHED, SocialPost::STATUS_DONE])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Сколько постов с брендом уже создано — стартовое смещение ротации пула брендов
     * в планировщике (иначе курсор сбрасывался в 0 на каждом прогоне → один и тот же бренд).
     */
    public function countWithBrand(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.brand IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Сколько постов этой рубрики уже создано — детерминированный курсор ротации записей
     * config-сида рубрики (напр. departed_brands.yaml), зеркало countWithBrand().
     */
    public function countByRubric(string $rubric): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.rubric = :r')
            ->setParameter('r', $rubric)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Fallback-атрибуция клика без utm_content (старые/битые ссылки): последний
     * опубликованный пост этой платформы+рубрики, чей published_at <= момента клика
     * и не старше $maxAgeDays от него (app:social:ingest-clicks).
     */
    public function findForClickAttribution(string $platform, string $rubric, \DateTimeInterface $clickAt, int $maxAgeDays): ?SocialPost
    {
        $earliest = (clone $clickAt)->modify("-{$maxAgeDays} days");

        return $this->createQueryBuilder('p')
            ->join('p.channel', 'c')
            ->where('c.platform = :platform')
            ->andWhere('p.rubric = :rubric')
            ->andWhere('p.publishedAt IS NOT NULL')
            ->andWhere('p.publishedAt <= :clickAt')
            ->andWhere('p.publishedAt >= :earliest')
            ->setParameter('platform', $platform)
            ->setParameter('rubric', $rubric)
            ->setParameter('clickAt', $clickAt)
            ->setParameter('earliest', $earliest)
            ->orderBy('p.publishedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Опубликованные посты каналов (IN) с внешним id, чей published_at не старше $since —
     * кандидаты сбора метрик (app:social:collect-metrics). Свежие сначала — им дают
     * досмотреться, а метрики старых уже стабилизировались.
     *
     * @param list<SocialChannel> $channels
     * @return SocialPost[]
     */
    public function findPublishedForMetrics(array $channels, \DateTimeInterface $since, int $limit): array
    {
        if ($channels === []) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->where('p.channel IN (:channels)')
            ->andWhere('p.status IN (:statuses)')
            ->andWhere('p.externalId IS NOT NULL')
            ->andWhere("p.externalId != ''")
            ->andWhere('p.publishedAt >= :since')
            ->setParameter('channels', $channels)
            ->setParameter('statuses', [SocialPost::STATUS_PUBLISHED, SocialPost::STATUS_DONE])
            ->setParameter('since', $since)
            ->orderBy('p.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Уже есть пост этой рубрики на этот день у канала? (дедуп при планировании). */
    public function existsForSlot(SocialChannel $channel, string $rubric, \DateTimeInterface $dayStart): bool
    {
        $dayEnd = (clone $dayStart)->modify('+1 day');

        return null !== $this->createQueryBuilder('p')
            ->select('p.id')
            ->where('p.channel = :ch')
            ->andWhere('p.rubric = :r')
            ->andWhere('p.scheduledAt >= :s AND p.scheduledAt < :e')
            ->setParameter('ch', $channel)
            ->setParameter('r', $rubric)
            ->setParameter('s', $dayStart)
            ->setParameter('e', $dayEnd)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
