<?php

declare(strict_types=1);

namespace App\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Nevinny\AdminCoreBundle\Entity\Trait\DefaultFields;

/**
 * Подставляет `parent = 0` новым сущностям, у которых поле не заполнено.
 *
 * Зачем: листинг EasyAdmin в `Nevinny\AdminCoreBundle\Controller\Admin\DefaultCrudController`
 * без параметра `parent_id` добавляет условие `entity.parent = 0`, а `NULL` под равенство
 * в SQL не попадает — запись физически есть, но в админке её не видно. Дефолт трейта
 * {@see DefaultFields} при этом `null`, поэтому всё, что создаётся кодом (импорты,
 * RAG-конвейер, LK), выпадало из админки.
 *
 * Разовая правка уже накопленных строк — `app:fix:null-parent`.
 */
#[AsDoctrineListener(event: Events::prePersist)]
class DefaultParentSubscriber
{
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        // Только сущности на трейте DefaultFields: у них parent — int. У сущностей со связью
        // parent (Main, ProductCategory) сеттер ждёт объект, туда 0 подставлять нельзя.
        if (!self::usesDefaultFields($entity::class)) {
            return;
        }

        if ($entity->getParent() === null) {
            $entity->setParent(0);
        }
    }

    private static function usesDefaultFields(string $class): bool
    {
        for ($c = $class; $c !== false; $c = get_parent_class($c)) {
            if (in_array(DefaultFields::class, class_uses($c) ?: [], true)) {
                return true;
            }
        }

        return false;
    }
}
