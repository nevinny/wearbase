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
        if ($pipeline !== null) {
            return $pipeline;
        }

        // findOneBy запрашивает БД и НЕ видит persisted-но-не-flushed сущность: при повторном
        // вызове в одном юните работы создалась бы ВТОРАЯ строка → flush → 1062
        // uniq_brand_rag_brand (откат всего батча discover → конвейер встаёт). Проверяем
        // запланированные вставки UoW и переиспользуем уже созданную для этого бренда.
        foreach ($this->getEntityManager()->getUnitOfWork()->getScheduledEntityInsertions() as $scheduled) {
            if ($scheduled instanceof BrandRagPipeline && $scheduled->getBrand() === $brand) {
                return $scheduled;
            }
        }

        $pipeline = (new BrandRagPipeline())->setBrand($brand);
        $this->getEntityManager()->persist($pipeline);

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
