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

    /**
     * Помечает доставляемые данные бренда изменёнными → push переотправит их на прод
     * (предикат contentChangedAt > pushedAt в BrandRepository::findReadyToPush).
     * Вызывать ТОЛЬКО когда данные реально записаны (иначе ложный ре-пуш всей базы).
     * НЕ делает flush — flush'ит вызывающая команда.
     */
    public function markContentChanged(Brand $brand): void
    {
        $this->getOrCreate($brand)->setContentChangedAt(new \DateTime());
    }
}
