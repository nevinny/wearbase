<?php

declare(strict_types=1);

namespace App\Service\Family;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use App\Entity\PurchaseRequest;
use App\Entity\User;
use App\Entity\WardrobeNeed;
use App\Repository\WardrobeNeedRepository;
use App\Service\FamilyService;
use App\Service\PurchaseRequestService;

final class WardrobeNeedService
{
    public function __construct(
        private readonly FamilyService $families,
        private readonly WardrobeNeedRepository $needs,
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return User[] */
    public function childrenFor(User $actor): array
    {
        if (!$actor->isFamilyParent() || $actor->getFamily() === null) {
            return [];
        }
        return array_values(array_filter($this->families->membersFor($actor),
            fn (User $member): bool => $member->getFamilyRole() === User::FAMILY_ROLE_CHILD
                && $this->families->canManage($actor, $member),
        ));
    }

    /** @return User[] */
    public function subjectsFor(User $actor): array
    {
        return [$actor, ...$this->childrenFor($actor)];
    }

    public function findForActor(User $actor, int $id): WardrobeNeed
    {
        $need = $this->needs->find($id);
        if (!$need instanceof WardrobeNeed) {
            throw new AccessDeniedException('Нет доступа к потребности');
        }
        $this->assertCanManage($actor, $need);
        return $need;
    }

    public function assertCanManage(User $actor, WardrobeNeed $need): void
    {
        if ($need->getFamily() === null) {
            if ($actor->getId() !== $need->getSubject()->getId()) {
                throw new AccessDeniedException('Нет доступа к личной потребности');
            }
            return;
        }
        if (!$actor->isFamilyParent() || $actor->getFamily() === null
            || $actor->getFamily()->getId() !== $need->getFamily()->getId()
            || !$this->families->canManage($actor, $need->getSubject())
            || $need->getSubject()->getFamilyRole() !== User::FAMILY_ROLE_CHILD
        ) {
            throw new AccessDeniedException('Нет доступа к потребности');
        }
    }

    public function save(User $actor, array $data, ?WardrobeNeed $need = null): WardrobeNeed
    {
        $need ??= new WardrobeNeed(
            $data['subject']->getId() === $actor->getId() ? null : $actor->getFamily(),
            $data['subject'],
        );
        $this->assertCanManage($actor, $need);
        if ($data['subject']->getId() !== $need->getSubject()->getId()) {
            throw new AccessDeniedException('Нельзя изменить владельца потребности');
        }
        $need->revise($data['category'], $data['season'], $data['title'], $data['quantity'], $data['size'], $data['notes']);
        $this->em->persist($need);
        $this->em->flush();
        return $need;
    }

    public function setOpen(User $actor, WardrobeNeed $need, bool $open): void
    {
        $this->assertCanManage($actor, $need);
        $open ? $need->reopen() : $need->close();
        $this->em->flush();
    }

    public function purchase(User $actor, WardrobeNeed $need, array $data, PurchaseRequestService $purchases): PurchaseRequest
    {
        $this->assertCanManage($actor, $need);
        if ($need->getFamily() === null) {
            throw new AccessDeniedException('Запрос покупки доступен только для семейной потребности ребёнка');
        }
        if (trim((string) ($data['additionalUrls'] ?? '')) !== '' || ($data['importMode'] ?? 'links') !== 'links') {
            throw new \InvalidArgumentException('Для потребности укажите ссылку на один товар');
        }
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->refresh($need, LockMode::PESSIMISTIC_WRITE);
            $this->assertCanManage($actor, $need);
            if (!$need->isOpen()) {
                throw new \DomainException('Потребность уже закрыта');
            }
            if ($data['subject']->getId() !== $need->getSubject()->getId()) {
                throw new \DomainException('Выберите владельца, для которого записана потребность');
            }
            if ($need->getPurchaseRequest() !== null) {
                $connection->commit();
                return $need->getPurchaseRequest();
            }
            $request = $purchases->create(
                $actor, $need->getSubject(), $data['productUrl'], $data['comment'], $data['estimatedPrice'],
                array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($data['additionalUrls'] ?? '')) ?: []))),
                ($data['importMode'] ?? 'links') === 'shared_cart',
            );
            $need->linkPurchaseRequest($request);
            $this->em->flush();
            $connection->commit();
            return $request;
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }
}
