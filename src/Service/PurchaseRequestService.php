<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PurchaseRequest;
use App\Entity\PurchaseRequestEvent;
use App\Entity\PurchaseRequestItem;
use App\Entity\Notification;
use App\Entity\FittingFeedback;
use App\Entity\User;
use App\Entity\WardrobeItem;
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
        array $additionalUrls = [],
    ): PurchaseRequest
    {
        $this->assertCanCreate($actor, $subject);

        if (count($additionalUrls) > 9) {
            throw new \InvalidArgumentException('В одном запросе можно до 10 вещей');
        }

        return $this->transactional(function () use ($actor, $subject, $productUrl, $comment, $estimatedPrice, $additionalUrls): PurchaseRequest {
            $request = (new PurchaseRequest())
                ->setFamily($subject->getFamily())
                ->setSubject($subject)
                ->setCreatedBy($actor)
                ->setProductUrl($productUrl)
                ->setEstimatedPrice($estimatedPrice)
                ->setComment($comment !== null && trim($comment) !== '' ? trim($comment) : null);
            $request->addItem(new PurchaseRequestItem($productUrl, $estimatedPrice));
            foreach ($additionalUrls as $url) {
                $request->addItem(new PurchaseRequestItem($url));
            }
            $request->addEvent(new PurchaseRequestEvent($actor, PurchaseRequestEvent::TYPE_CREATED));

            $this->em->persist($request);
            $this->em->flush();

            foreach ($this->families->membersFor($subject) as $member) {
                if ($member->isFamilyParent() && $member->getId() !== $actor->getId()) {
                    $this->notifyOnce(
                        $member,
                        Notification::TYPE_PURCHASE_REQUEST_NEW,
                        sprintf('purchase-request:%d:new:recipient:%d', $request->getId(), $member->getId()),
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
        $item = $request->getItems()->first();
        if (!$item instanceof PurchaseRequestItem) {
            throw new \DomainException('В запросе нет позиции');
        }
        $this->decideItem($actor, $request, $item, $decision, $comment, $allowOverBudget);
    }

    public function decideItem(
        User $actor,
        PurchaseRequest $request,
        PurchaseRequestItem $item,
        string $decision,
        ?string $comment = null,
        bool $allowOverBudget = false,
    ): void {
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

        if ($item->getPurchaseRequest()?->getId() !== $request->getId()) {
            throw new AccessDeniedException('Позиция не принадлежит запросу');
        }

        $this->transactional(function () use ($actor, $request, $item, $decision, $comment, $allowOverBudget): void {
            $this->em->refresh($request, LockMode::PESSIMISTIC_WRITE);
            foreach ($request->getItems() as $requestItem) {
                $this->em->refresh($requestItem, $requestItem === $item ? LockMode::PESSIMISTIC_WRITE : null);
            }
            $subject = $request->getSubject();
            \assert($subject instanceof User);

            $budget = $decision === PurchaseRequest::STATUS_APPROVED
                ? $this->budgets->checkApproval($subject, $item->getEstimatedPrice(), $allowOverBudget)
                : null;
            $item->decide($decision, $actor, $comment);
            $request->refreshDecisionFromItems();
            $overBudget = $budget !== null && $budget['exceeded'];
            $priceOverride = $decision === PurchaseRequest::STATUS_APPROVED
                && $item->getEstimatedPrice() === null
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
                'requested' => $item->getEstimatedPrice() ?? 'unknown',
                'override' => true,
                'reason' => $priceOverride ? 'unknown_price' : 'over_budget',
            ] : null;
            $event = (new PurchaseRequestEvent($actor, $eventType, $metadata))->setItem($item);
            $request->addEvent($event);
            $this->notifyOnce(
                $subject,
                Notification::TYPE_PURCHASE_REQUEST_DECIDED,
                sprintf('purchase-item:%d:decision:%s:recipient:%d', $item->getId(), $decision, $subject->getId()),
                $decision === PurchaseRequest::STATUS_APPROVED ? 'Покупка одобрена' : 'Покупка отклонена',
                $comment,
                ['url' => '/account/purchases/'.$request->getId()],
            );
        });
    }

    public function markOrdered(User $actor, PurchaseRequest $request, PurchaseRequestItem $item, ?string $actualPrice, bool $allowOverBudget = false): void
    {
        $this->assertParentCanManageItem($actor, $request, $item);
        $this->transactional(function () use ($actor, $request, $item, $actualPrice, $allowOverBudget): void {
            $this->em->refresh($item, LockMode::PESSIMISTIC_WRITE);
            $subject = $request->getSubject();
            \assert($subject instanceof User);
            $snapshot = $this->budgets->reconcileOrder($subject, $item, $actualPrice, $allowOverBudget);
            $item->markOrdered($actualPrice);
            $request->addEvent((new PurchaseRequestEvent(
                $actor,
                ($snapshot['override'] ?? false) ? PurchaseRequestEvent::TYPE_ORDERED_OVER_BUDGET : PurchaseRequestEvent::TYPE_ORDERED,
                $snapshot,
            ))->setItem($item));
            if ($actor->getId() !== $subject->getId()) {
                $this->notifications->dispatchInApp(
                    $subject,
                    Notification::TYPE_PURCHASE_REQUEST_DECIDED,
                    'Статус покупки обновлён',
                    null,
                    ['url' => '/account/purchases/'.$request->getId()],
                );
            }
        });
    }

    public function markDelivered(User $actor, PurchaseRequest $request, PurchaseRequestItem $item): void
    {
        $this->assertParentCanManageItem($actor, $request, $item);
        $this->mutateItem($actor, $request, $item, PurchaseRequestEvent::TYPE_DELIVERED, static function (PurchaseRequestItem $locked): void {
            $locked->markDelivered();
        });
    }

    /** @param string[] $fitIssues */
    public function recordFitting(
        User $actor,
        PurchaseRequest $request,
        PurchaseRequestItem $item,
        string $outcome,
        ?string $triedSize,
        ?string $sizing,
        array $fitIssues,
        ?string $comment,
    ): void {
        $this->assertCanRecordFitting($actor, $request, $item);
        $notificationType = match ($outcome) {
            FittingFeedback::OUTCOME_BOUGHT => Notification::TYPE_PURCHASE_BOUGHT,
            FittingFeedback::OUTCOME_REFUSED, FittingFeedback::OUTCOME_DIFFERENT_SIZE => Notification::TYPE_PURCHASE_REFUSED,
            default => Notification::TYPE_PURCHASE_FITTING,
        };
        $this->mutateItem($actor, $request, $item, PurchaseRequestEvent::TYPE_FITTING, static function (PurchaseRequestItem $locked) use ($actor, $outcome, $triedSize, $sizing, $fitIssues, $comment): void {
            $locked->recordFitting(new FittingFeedback($actor, $outcome, $triedSize, $sizing, $fitIssues, $comment));
        }, $notificationType, in_array($outcome, [FittingFeedback::OUTCOME_REFUSED, FittingFeedback::OUTCOME_DIFFERENT_SIZE], true));
    }

    public function markReturned(User $actor, PurchaseRequest $request, PurchaseRequestItem $item): void
    {
        $this->assertParentCanManageItem($actor, $request, $item);
        $this->transactional(function () use ($actor, $request, $item): void {
            $this->em->refresh($item, LockMode::PESSIMISTIC_WRITE);
            if ($item->getStatus() === PurchaseRequestItem::STATUS_RETURNED) {
                return;
            }

            $wardrobeItem = $item->getWardrobeItem();
            if ($wardrobeItem !== null) {
                $this->em->lock($wardrobeItem, LockMode::PESSIMISTIC_WRITE);
            }
            $subject = $request->getSubject();
            \assert($subject instanceof User);
            $this->budgets->lockForAccounting($subject);
            $item->markReturned();
            $wardrobeItem?->setItemStatus(WardrobeItem::ITEM_RETURNED);
            $request->addEvent((new PurchaseRequestEvent($actor, PurchaseRequestEvent::TYPE_RETURNED))->setItem($item));

            $this->notifyParents($actor, $subject, $request, $item, Notification::TYPE_PURCHASE_RETURNED);
            if ($actor->getId() !== $subject->getId()) {
                $this->notifyOnce(
                    $subject,
                    Notification::TYPE_PURCHASE_REQUEST_DECIDED,
                    sprintf('purchase-item:%d:returned:recipient:%d', $item->getId(), $subject->getId()),
                    'Покупка возвращена продавцу',
                    null,
                    ['url' => '/account/purchases/'.$request->getId()],
                );
            }
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

    private function assertParentCanManageItem(User $actor, PurchaseRequest $request, PurchaseRequestItem $item): void
    {
        $subject = $request->getSubject();
        if (!$actor->isFamilyParent()
            || $subject === null
            || $actor->getFamily()?->getId() !== $request->getFamily()?->getId()
            || $subject->getFamily()?->getId() !== $request->getFamily()?->getId()
            || $item->getPurchaseRequest()?->getId() !== $request->getId()
        ) {
            throw new AccessDeniedException('Нет доступа к позиции');
        }
    }

    private function assertCanRecordFitting(User $actor, PurchaseRequest $request, PurchaseRequestItem $item): void
    {
        if ($actor->getId() === $request->getSubject()?->getId()
            && $item->getPurchaseRequest()?->getId() === $request->getId()
        ) {
            return;
        }
        $this->assertParentCanManageItem($actor, $request, $item);
    }

    /** @param callable(PurchaseRequestItem): void $mutation */
    private function mutateItem(User $actor, PurchaseRequest $request, PurchaseRequestItem $item, string $eventType, callable $mutation, ?string $parentNotificationType = null, bool $releasesBudget = false): void
    {
        $this->transactional(function () use ($actor, $request, $item, $eventType, $mutation, $parentNotificationType, $releasesBudget): void {
            $this->em->refresh($item, LockMode::PESSIMISTIC_WRITE);
            $subject = $request->getSubject();
            \assert($subject instanceof User);
            if ($releasesBudget) {
                $this->budgets->lockForAccounting($subject);
            }
            $mutation($item);
            $request->addEvent((new PurchaseRequestEvent($actor, $eventType))->setItem($item));
            if ($parentNotificationType !== null) {
                $this->notifyParents($actor, $subject, $request, $item, $parentNotificationType);
            }
            if ($actor->getId() !== $subject->getId()) {
                $this->notifyOnce(
                    $subject,
                    Notification::TYPE_PURCHASE_REQUEST_DECIDED,
                    sprintf('purchase-item:%d:%s:recipient:%d', $item->getId(), $eventType, $subject->getId()),
                    'Статус покупки обновлён',
                    null,
                    ['url' => '/account/purchases/'.$request->getId()],
                );
            }
        });
    }

    private function notifyParents(User $actor, User $subject, PurchaseRequest $request, PurchaseRequestItem $item, string $type): void
    {
        $titles = [
            Notification::TYPE_PURCHASE_FITTING => '%s оставил(а) результат примерки',
            Notification::TYPE_PURCHASE_BOUGHT => '%s выкупил(а) вещь',
            Notification::TYPE_PURCHASE_REFUSED => '%s отказался(-ась) от вещи',
            Notification::TYPE_PURCHASE_RETURNED => 'Вещь для %s возвращена',
        ];
        foreach ($this->families->membersFor($subject) as $parent) {
            if (!$parent->isFamilyParent() || $parent->getId() === $actor->getId()) {
                continue;
            }
            $this->notifyOnce(
                $parent,
                $type,
                sprintf('purchase-item:%d:%s:recipient:%d', $item->getId(), $type, $parent->getId()),
                sprintf($titles[$type], $subject->getFirstName() ?: 'Ребёнок'),
                null,
                ['url' => '/account/purchases/'.$request->getId()],
            );
        }
    }

    /** @param array<string, mixed> $data */
    private function notifyOnce(User $recipient, string $type, string $dedupeKey, string $title, ?string $body, array $data): void
    {
        $this->notifications->dispatchOnce(
            $recipient,
            $type,
            $dedupeKey,
            $title,
            $body,
            $data,
            'family_notification',
            ['title' => $title, 'body' => $body, 'url' => $data['url'] ?? null],
        );
    }
}
