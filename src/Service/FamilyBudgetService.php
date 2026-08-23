<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FamilyBudget;
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

        $budget = $this->budgets->findForSubject($subject) ?? (new FamilyBudget())
            ->setSubject($subject);
        $budget->setMonthlyLimit($limit);

        $this->em->persist($budget);
        $this->em->flush();

        return $budget;
    }

    /**
     * @return array{limit: string, approved: string, remaining: string, exceeded: bool}|null
     */
    public function summary(User $subject, ?string $additional = null): ?array
    {
        $budget = $this->budgets->findForSubject($subject);
        if ($budget === null) {
            return null;
        }

        $limit = MoneyAmount::toMinor($budget->getMonthlyLimit());
        $approved = MoneyAmount::toMinor($this->purchaseRequests->approvedAmountForMonth($subject, new \DateTimeImmutable()));
        $remaining = $limit - $approved;
        $additionalMinor = $additional === null ? null : MoneyAmount::toMinor($additional);

        return [
            'limit' => MoneyAmount::fromMinor($limit),
            'approved' => MoneyAmount::fromMinor($approved),
            'remaining' => MoneyAmount::fromMinor($remaining),
            'exceeded' => $additionalMinor !== null && $additionalMinor > max(0, $remaining),
        ];
    }

    /**
     * @return array{limit: string, approved: string, remaining: string, exceeded: bool}|null
     */
    public function checkApproval(User $subject, ?string $price, bool $allowOverBudget): ?array
    {
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
