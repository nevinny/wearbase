<?php
namespace App\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Symfony\Bundle\SecurityBundle\Security;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
//#[AsDoctrineListener(event: Events::postFlush)]
class EntityUserListener
{
    private Security $security;

    public function __construct(Security $security)
    {
        $this->security = $security;
    }

    public function prePersist(LifecycleEventArgs $event): void
    {
        $entity = $event->getObject();
        $user   = $this->security->getUser();

        // blameable-поля могут ждать админского User (Nevinny) — ставим только если тип совпадает
        $this->setBlameable($entity, 'setCreatedBy', $user);
        $this->setBlameable($entity, 'setUpdatedBy', $user);

        if (method_exists($entity, 'setCreatedAt') && method_exists($entity, 'setUpdatedAt')) {
            $now = new \DateTime();
            $entity->setCreatedAt($now);
            $entity->setUpdatedAt($now);
        }
    }

    public function preUpdate(LifecycleEventArgs $event): void
    {
        $entity = $event->getObject();

        $this->setBlameable($entity, 'setUpdatedBy', $this->security->getUser());

        if (method_exists($entity, 'setUpdatedAt')) {
            $entity->setUpdatedAt(new \DateTime());
        }
    }

    /**
     * Вызывает setCreatedBy/setUpdatedBy только если текущий пользователь
     * совместим с типом, который принимает сеттер (две разные User-сущности:
     * App\Entity\User на фронте и Nevinny\AdminCoreBundle\Entity\User в админке).
     */
    private function setBlameable(object $entity, string $method, ?object $user): void
    {
        if ($user === null || !method_exists($entity, $method)) {
            return;
        }

        $type = (new \ReflectionMethod($entity, $method))->getParameters()[0]?->getType();
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            $expected = $type->getName();
            if (!$user instanceof $expected) {
                return; // тип не подходит — пропускаем (напр. App\User для админского поля)
            }
        }

        $entity->{$method}($user);
    }
}
