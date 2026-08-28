<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Brand;
use App\Entity\BrandClaim;
use App\Entity\Subscription;
use App\Entity\Tariff;
use App\Entity\User;
use App\EventListener\AdminTelegramSubscriber;
use App\Notification\AdminNotifier;
use PHPUnit\Framework\TestCase;

class AdminTelegramSubscriberTest extends TestCase
{
    private function subscriber(): AdminTelegramSubscriber
    {
        return new AdminTelegramSubscriber($this->createMock(AdminNotifier::class));
    }

    public function testRegistrationMessage(): void
    {
        $msg = $this->subscriber()->buildMessage((new User())->setEmail('new@user.com'));
        $this->assertStringContainsString('Новая регистрация', $msg);
        $this->assertStringContainsString('new@user.com', $msg);
    }

    public function testBrandClaimDoesNotPingOnInsert(): void
    {
        // Строка заявки создаётся при действии в форме, а не при подаче на модерацию,
        // и о реальной подаче админа уведомляет BrandClaimController::notifyAdmin().
        // Пинг на вставку означал «кто-то открыл форму» — убран.
        $user  = (new User())->setEmail('owner@brand.com');
        $brand = (new Brand())->setTitle('Acme');
        $claim = (new BrandClaim())->setBrand($brand)->setUser($user)->setMethod(BrandClaim::METHOD_EMAIL_CODE);

        $this->assertNull($this->subscriber()->buildMessage($claim));
    }

    public function testSubscriptionMessage(): void
    {
        $brand  = (new Brand())->setTitle('Acme');
        $tariff = (new Tariff())->setName('Free')->setCode(Tariff::CODE_FREE);
        $sub    = (new Subscription())->setBrand($brand)->setTariff($tariff);

        $msg = $this->subscriber()->buildMessage($sub);
        $this->assertStringContainsString('Новая подписка', $msg);
        $this->assertStringContainsString('Acme', $msg);
        $this->assertStringContainsString('free', $msg);
    }

    public function testHtmlIsEscaped(): void
    {
        $msg = $this->subscriber()->buildMessage((new User())->setEmail('<b>x</b>@e.com'));
        $this->assertStringContainsString('&lt;b&gt;', $msg);
        $this->assertStringNotContainsString('<b>x</b>', $msg);
    }

    public function testUnrelatedEntityIgnored(): void
    {
        $this->assertNull($this->subscriber()->buildMessage(new Brand()));
    }
}
