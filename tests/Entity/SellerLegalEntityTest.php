<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SellerLegalEntity;
use App\Entity\SellerPaymentAccount;
use PHPUnit\Framework\TestCase;

class SellerLegalEntityTest extends TestCase
{
    private function account(bool $primary, string $status): SellerPaymentAccount
    {
        $acc = new SellerPaymentAccount();
        $acc->setIsPrimary($primary);
        $acc->setStatus($status);
        return $acc;
    }

    public function testNoAccountsReturnsNull(): void
    {
        $this->assertNull((new SellerLegalEntity())->getPrimaryPaymentAccount());
    }

    public function testReturnsActivePrimaryAccount(): void
    {
        $entity = new SellerLegalEntity();
        $entity->addPaymentAccount($this->account(false, SellerPaymentAccount::STATUS_ACTIVE));
        $primary = $this->account(true, SellerPaymentAccount::STATUS_ACTIVE);
        $entity->addPaymentAccount($primary);

        $this->assertSame($primary, $entity->getPrimaryPaymentAccount());
    }

    public function testDisabledPrimaryIsIgnored(): void
    {
        $entity = new SellerLegalEntity();
        $entity->addPaymentAccount($this->account(true, SellerPaymentAccount::STATUS_DISABLED));

        $this->assertNull($entity->getPrimaryPaymentAccount());
    }

    public function testNonPrimaryActiveIsIgnored(): void
    {
        $entity = new SellerLegalEntity();
        $entity->addPaymentAccount($this->account(false, SellerPaymentAccount::STATUS_ACTIVE));

        $this->assertNull($entity->getPrimaryPaymentAccount());
    }
}
