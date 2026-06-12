<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Order;
use PHPUnit\Framework\TestCase;

class OrderRefundTest extends TestCase
{
    public function testNoRequestMeansNoDeadlineAndNotOverdue(): void
    {
        $order = new Order();

        $this->assertNull($order->getRefundConfirmationDeadline());
        $this->assertFalse($order->isRefundConfirmationOverdue(new \DateTimeImmutable()));
    }

    public function testDeadlineIsTenDaysAfterRequest(): void
    {
        $order = new Order();
        $order->setPrepaymentRefundRequestedAt(new \DateTimeImmutable('2026-06-01'));

        $this->assertEquals(
            new \DateTimeImmutable('2026-06-11'),
            $order->getRefundConfirmationDeadline(),
        );
    }

    public function testOverdueWhenDeadlinePassedAndConfirmationNotSent(): void
    {
        $order = new Order();
        $order->setPrepaymentRefundRequestedAt(new \DateTimeImmutable('2026-06-01'));

        $this->assertTrue($order->isRefundConfirmationOverdue(new \DateTimeImmutable('2026-06-12')));
        $this->assertFalse($order->isRefundConfirmationOverdue(new \DateTimeImmutable('2026-06-05')));
    }

    public function testNotOverdueOnceConfirmationSent(): void
    {
        $order = new Order();
        $order->setPrepaymentRefundRequestedAt(new \DateTimeImmutable('2026-06-01'));
        $order->setRefundConfirmationSentAt(new \DateTimeImmutable('2026-06-09'));

        $this->assertFalse($order->isRefundConfirmationOverdue(new \DateTimeImmutable('2026-06-20')));
    }
}
