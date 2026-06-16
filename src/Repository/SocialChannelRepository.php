<?php

namespace App\Repository;

use App\Entity\SocialChannel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialChannel>
 */
class SocialChannelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialChannel::class);
    }

    /** @return SocialChannel[] */
    public function findEnabled(): array
    {
        return $this->findBy(['enabled' => true]);
    }

    /**
     * Включённые каналы, которые публикуются с данного egress-хоста (mac|prod).
     * publish-tick на каждом хосте берёт только свои каналы.
     *
     * @return SocialChannel[]
     */
    public function findEnabledByHost(string $host): array
    {
        return $this->findBy(['enabled' => true, 'egressHost' => $host]);
    }
}
