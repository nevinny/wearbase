<?php

namespace App\Repository;

use App\Entity\Brand;
use App\Entity\BrandRagPipeline;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrandRagPipeline>
 */
class BrandRagPipelineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrandRagPipeline::class);
    }

    /**
     * Возвращает pipeline-строку бренда, создавая её (в статусе pending), если нет.
     * НЕ делает flush — это забота вызывающей команды.
     */
    public function getOrCreate(Brand $brand): BrandRagPipeline
    {
        $pipeline = $this->findOneBy(['brand' => $brand]);
        if ($pipeline === null) {
            $pipeline = (new BrandRagPipeline())->setBrand($brand);
            $this->getEntityManager()->persist($pipeline);
        }

        return $pipeline;
    }
}
