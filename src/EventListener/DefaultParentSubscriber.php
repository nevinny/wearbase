<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Command\FixNullParentCommand;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Подставляет `parent = 0` новым сущностям, у которых поле не заполнено.
 *
 * Зачем: листинг EasyAdmin в `Nevinny\AdminCoreBundle\Controller\Admin\DefaultCrudController`
 * без параметра `parent_id` добавляет условие `entity.parent = 0`, а `NULL` под равенство
 * в SQL не попадает — запись физически есть, но в админке её не видно. Дефолт поля при этом
 * `null`, поэтому всё, что создаётся кодом (импорты, RAG-конвейер, ЛК), выпадало из админки.
 *
 * Разовая правка уже накопленных строк — `app:fix:null-parent`.
 */
#[AsDoctrineListener(event: Events::prePersist)]
class DefaultParentSubscriber
{
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        // Только скалярный int-parent (Brand объявляет его сам, вне трейта DefaultFields).
        // У сущностей со СВЯЗЬЮ parent сеттер ждёт объект — туда 0 подставлять нельзя.
        $metadata = $args->getObjectManager()->getClassMetadata($entity::class);
        if (!FixNullParentCommand::hasScalarParent($metadata)) {
            return;
        }

        if ($entity->getParent() === null) {
            $entity->setParent(0);
        }
    }
}
