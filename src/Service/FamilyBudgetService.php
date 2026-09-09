<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FamilyBudget;
use App\Entity\PurchaseRequestItem;
use App\Entity\User;
use App\Repository\FamilyBudgetRepository;
use App\Repository\PurchaseRequestRepository;
use App\ValueObject\MoneyAmount;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class FamilyBudgetService
{
    public function __construct(
        private readonly FamilyBudgetRepository $budgets,
        private readonly PurchaseRequestRepository $purchaseRequests,
        private readonly EntityManagerInterface $em,
    ) {}

    public function setMonthlyLimit(User $actor, User $subject, string $limit): FamilyBudget
    {
        $this->assertParentOf($actor, $subject);
        $normalizedLimit = MoneyAmount::normalize($limit);
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->lock($subject, LockMode::PESSIMISTIC_WRITE);
            $budget = $this->budgets->findForSubject($subject) ?? (new FamilyBudget())
                ->setSubject($subject);
            $budget->setMonthlyLimit($normalizedLimit);

            $this->em->persist($budget);
            $this->em->flush();
            $connection->commit();

            return $budget;
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return array{limit: string, approved: string, remaining: string, exceeded: bool}|null
     */
    public function summary(User $subject, ?string $additional = null, ?\DateTimeImmutable $month = null): ?array
    {
        $budget = $this->budgets->findForSubject($subject);
        if ($budget === null) {
            return null;
        }

        $limit = MoneyAmount::toMinor($budget->getMonthlyLimit());
        $approved = MoneyAmount::toMinor($this->purchaseRequests->approvedAmountForMonth($subject, $month ?? new \DateTimeImmutable()));
        $remaining = $limit - $approved;
        $additionalMinor = $additional === null ? null : MoneyAmount::toMinor($additional);

        return [
            'limit' => MoneyAmount::fromMinor($limit),
            'approved' => MoneyAmount::fromMinor($approved),
            'remaining' => MoneyAmount::fromMinor($remaining),
            'exceeded' => $additionalMinor !== null && $additionalMinor > max(0, $remaining),
        ];
    }

    /** @return array<string, string|bool>|null */
    public function reconcileOrder(User $subject, PurchaseRequestItem $item, ?string $actualPrice, bool $allowOverBudget): ?array
    {
        $this->em->lock($subject, LockMode::PESSIMISTIC_WRITE);
        $budget = $this->budgets->findForSubject($subject);
        if ($budget === null) {
            return null;
        }
        $this->em->lock($budget, LockMode::PESSIMISTIC_WRITE);

        $price = $actualPrice === null ? $item->getEstimatedPrice() : MoneyAmount::normalize($actualPrice);
        $month = $item->getDecidedAt() ?? new \DateTimeImmutable();
        $limit = MoneyAmount::toMinor($budget->getMonthlyLimit());
        $before = MoneyAmount::toMinor($this->purchaseRequests->approvedAmountForMonth($subject, $month));
        $other = MoneyAmount::toMinor($this->purchaseRequests->approvedAmountForMonth($subject, $month, $item));
        $after = $other + ($price === null ? 0 : MoneyAmount::toMinor($price));
        $exceeded = $price === null || $after > $limit;
        if ($exceeded && !$allowOverBudget) {
            throw new \DomainException($price === null
                ? 'Укажите фактическую цену или подтвердите заказ без проверки бюджета.'
                : 'Фактическая цена превышает месячный бюджет. Подтвердите перерасход.');
        }

        return [
            'limit' => MoneyAmount::fromMinor($limit),
            'committedBefore' => MoneyAmount::fromMinor($before),
            'remainingBefore' => MoneyAmount::fromMinor($limit - $before),
            'estimated' => $item->getEstimatedPrice() ?? 'unknown',
            'actual' => $price ?? 'unknown',
            'committedAfter' => MoneyAmount::fromMinor($after),
            'remainingAfter' => MoneyAmount::fromMinor($limit - $after),
            'delta' => MoneyAmount::fromMinor($after - $before),
            'override' => $exceeded,
            'reason' => $price === null ? 'unknown_actual_price' : ($exceeded ? 'actual_price_over_budget' : 'actual_price_reconciled'),
        ];
    }

    public function lockForAccounting(User $subject): void
    {
        $this->em->lock($subject, LockMode::PESSIMISTIC_WRITE);
        $budget = $this->budgets->findForSubject($subject);
        if ($budget !== null) {
            $this->em->lock($budget, LockMode::PESSIMISTIC_WRITE);
        }
    }

    /**
     * @return array{limit: string, approved: string, remaining: string, exceeded: bool}|null
     */
    public function checkApproval(User $subject, ?string $price, bool $allowOverBudget): ?array
    {
        $this->em->lock($subject, LockMode::PESSIMISTIC_WRITE);
        $budget = $this->budgets->findForSubject($subject);
        if ($budget === null) {
            return null;
        }
        $this->em->lock($budget, LockMode::PESSIMISTIC_WRITE);
        if ($price === null) {
            if (!$allowOverBudget) {
                throw new \DomainException('Укажите цену или подтвердите покупку без проверки бюджета.');
            }
            return $this->summary($subject);
        }

        $summary = $this->summary($subject, $price);
        if ($summary !== null && $summary['exceeded'] && !$allowOverBudget) {
            throw new \DomainException('Покупка превышает остаток бюджета. Подтвердите перерасход.');
        }

        return $summary;
    }

    private function assertParentOf(User $actor, User $subject): void
    {
        if (!$actor->isFamilyParent()
            || $actor->getFamily() === null
            || $actor->getFamily()?->getId() !== $subject->getFamily()?->getId()
            || $subject->getFamilyRole() !== User::FAMILY_ROLE_CHILD
        ) {
            throw new AccessDeniedException('Бюджет может изменить только родитель этой семьи');
        }
    }
}
