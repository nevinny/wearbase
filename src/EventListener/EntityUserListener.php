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

        if (method_exists($entity, 'setCreatedBy') && method_exists($entity, 'setUpdatedBy')) {
            $user = $this->security->getUser();
            if ($user) {
                $entity->setCreatedBy($user);
                $entity->setUpdatedBy($user);
            }
        }

        if (method_exists($entity, 'setCreatedAt') && method_exists($entity, 'setUpdatedAt')) {
            $now = new \DateTime();
            $entity->setCreatedAt($now);
            $entity->setUpdatedAt($now);
        }
    }

    public function preUpdate(LifecycleEventArgs $event): void
    {
        $entity = $event->getObject();

        if (method_exists($entity, 'setUpdatedBy')) {
            $user = $this->security->getUser();
            if ($user) {
                $entity->setUpdatedBy($user);
            }
        }

        if (method_exists($entity, 'setUpdatedAt')) {
            $entity->setUpdatedAt(new \DateTime());
        }
    }
}
