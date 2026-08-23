<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PurchaseRequest;
use App\Entity\PurchaseRequestEvent;
use App\Entity\Notification;
use App\Entity\User;
use App\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class PurchaseRequestService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FamilyService $families,
        private readonly FamilyBudgetService $budgets,
        private readonly NotificationDispatcher $notifications,
    ) {}

    public function create(
        User $actor,
        User $subject,
        string $productUrl,
        ?string $comment,
        ?string $estimatedPrice = null,
    ): PurchaseRequest
    {
        $this->assertCanCreate($actor, $subject);

        return $this->transactional(function () use ($actor, $subject, $productUrl, $comment, $estimatedPrice): PurchaseRequest {
            $request = (new PurchaseRequest())
                ->setFamily($subject->getFamily())
                ->setSubject($subject)
                ->setCreatedBy($actor)
                ->setProductUrl($productUrl)
                ->setEstimatedPrice($estimatedPrice)
                ->setComment($comment !== null && trim($comment) !== '' ? trim($comment) : null);
            $request->addEvent(new PurchaseRequestEvent($actor, PurchaseRequestEvent::TYPE_CREATED));

            $this->em->persist($request);
            $this->em->flush();

            foreach ($this->families->membersFor($subject) as $member) {
                if ($member->isFamilyParent() && $member->getId() !== $actor->getId()) {
                    $this->notifications->dispatchInApp(
                        $member,
                        Notification::TYPE_PURCHASE_REQUEST_NEW,
                        sprintf('%s просит согласовать покупку', $subject->getFirstName() ?: 'Ребёнок'),
                        $request->getComment(),
                        ['url' => '/account/purchases/'.$request->getId()],
                    );
                }
            }

            return $request;
        });
    }

    public function assertCanRead(User $actor, PurchaseRequest $request): void
    {
        if ($actor->getId() === $request->getSubject()?->getId()) {
            return;
        }

        if (!$actor->isFamilyParent()
            || $actor->getFamily() === null
            || $actor->getFamily()?->getId() !== $request->getFamily()?->getId()
            || $request->getSubject()?->getFamily()?->getId() !== $request->getFamily()?->getId()
        ) {
            throw new AccessDeniedException('Нет доступа к запросу на покупку');
        }
    }

    public function decide(
        User $actor,
        PurchaseRequest $request,
        string $decision,
        ?string $comment = null,
        bool $allowOverBudget = false,
    ): void
    {
        $subject = $request->getSubject();
        if ($subject === null
            || !$actor->isFamilyParent()
            || $actor->getFamily() === null
            || $actor->getFamily()?->getId() !== $request->getFamily()?->getId()
            || $subject->getFamily()?->getId() !== $request->getFamily()?->getId()
            || $subject->getFamilyRole() !== User::FAMILY_ROLE_CHILD
        ) {
            throw new AccessDeniedException('Решение может принять только родитель этой семьи');
        }

        $this->transactional(function () use ($actor, $request, $decision, $comment, $allowOverBudget): void {
            $this->em->refresh($request, LockMode::PESSIMISTIC_WRITE);
            $subject = $request->getSubject();
            \assert($subject instanceof User);

            $budget = $decision === PurchaseRequest::STATUS_APPROVED
                ? $this->budgets->checkApproval($subject, $request->getEstimatedPrice(), $allowOverBudget)
                : null;
            $request->decide($decision, $actor, $comment);
            $overBudget = $budget !== null && $budget['exceeded'];
            $priceOverride = $decision === PurchaseRequest::STATUS_APPROVED
                && $request->getEstimatedPrice() === null
                && $budget !== null
                && $allowOverBudget;
            $eventType = $overBudget
                ? PurchaseRequestEvent::TYPE_APPROVED_OVER_BUDGET
                : ($priceOverride
                    ? PurchaseRequestEvent::TYPE_APPROVED_NO_PRICE
                : ($decision === PurchaseRequest::STATUS_APPROVED
                    ? PurchaseRequestEvent::TYPE_APPROVED
                    : PurchaseRequestEvent::TYPE_REJECTED));
            $metadata = $overBudget || $priceOverride ? [
                'limit' => (string) $budget['limit'],
                'approvedBefore' => (string) $budget['approved'],
                'remainingBefore' => (string) $budget['remaining'],
                'requested' => $request->getEstimatedPrice() ?? 'unknown',
                'override' => true,
                'reason' => $priceOverride ? 'unknown_price' : 'over_budget',
            ] : null;
            $request->addEvent(new PurchaseRequestEvent($actor, $eventType, $metadata));
            $this->notifications->dispatchInApp(
                $subject,
                Notification::TYPE_PURCHASE_REQUEST_DECIDED,
                $decision === PurchaseRequest::STATUS_APPROVED ? 'Покупка одобрена' : 'Покупка отклонена',
                $comment,
                ['url' => '/account/purchases/'.$request->getId()],
            );
        });
    }

    /**
     * Keeps the EntityManager usable after an expected domain rejection.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transactional(callable $operation): mixed
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $result = $operation();
            $this->em->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    private function assertCanCreate(User $actor, User $subject): void
    {
        if ($actor->getId() === $subject->getId()
            && $actor->getFamilyRole() === User::FAMILY_ROLE_CHILD
            && $actor->getFamily() !== null
        ) {
            return;
        }

        if ($actor->isFamilyParent()
            && $actor->getFamily() !== null
            && $actor->getFamily()?->getId() === $subject->getFamily()?->getId()
            && $subject->getFamilyRole() === User::FAMILY_ROLE_CHILD
        ) {
            return;
        }

        throw new AccessDeniedException('Запрос можно создать только для ребёнка своей семьи');
    }
}
