<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SellerPaymentAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SellerPaymentAccount>
 */
class SellerPaymentAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SellerPaymentAccount::class);
    }

    /**
     * Сколько брендов из «гейта продажи» (опубликован + есть владелец + есть хотя бы
     * один активный товар — та же популяция, что BrandRepository::findActiveOwnedWithProducts
     * / app:brand:payment-reminders) НЕ имеют готового к приёму оплаты счёта — зеркало
     * BrandSaleExtension::canSell() одним COUNT вместо N+1 по каждому бренду.
     */
    public function countSaleGatedBrands(): int
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM brand b
             WHERE b.status = 'active'
               AND b.published_at IS NOT NULL
               AND EXISTS (SELECT 1 FROM brand_user bu WHERE bu.brand_id = b.id AND bu.role = 'owner')
               AND EXISTS (SELECT 1 FROM product p WHERE p.brand_id = b.id AND p.status = 'active')
               AND NOT EXISTS (
                    SELECT 1 FROM seller_legal_entity e
                    JOIN seller_payment_account spa ON spa.legal_entity_id = e.id
                    JOIN payment_provider pp ON pp.id = spa.provider_id
                    WHERE e.brand_id = b.id
                      AND e.status = 'active'
                      AND (e.effective_from IS NULL OR e.effective_from <= ?)
                      AND (e.effective_to IS NULL OR e.effective_to >= ?)
                      AND spa.is_primary = 1
                      AND spa.status = 'active'
                      AND pp.code = 'yookassa'
                      AND spa.account_ref IS NOT NULL AND spa.account_ref != ''
                      AND spa.secret_encrypted IS NOT NULL AND spa.secret_encrypted != ''
               )",
            [$today, $today],
        );
    }
}
