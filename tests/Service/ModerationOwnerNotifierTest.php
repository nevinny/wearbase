<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Brand;
use App\Entity\BrandModeration;
use App\Entity\BrandUser;
use App\Entity\Notification;
use App\Entity\User;
use App\Notification\NotificationDispatcher;
use App\Repository\BrandUserRepository;
use App\Repository\NotificationRepository;
use App\Service\Moderation\ModerationLabels;
use App\Service\Moderation\ModerationOwnerNotifier;
use PHPUnit\Framework\TestCase;

class ModerationOwnerNotifierTest extends TestCase
{
    private function notifierFor(?User $owner, bool $alreadyNotified = false): array
    {
        $dispatcher = $this->createMock(NotificationDispatcher::class);
        $repo = $this->createMock(BrandUserRepository::class);
        $notifications = $this->createMock(NotificationRepository::class);
        $notifications->method('findOneBy')->willReturn($alreadyNotified ? new Notification() : null);

        $brandUser = null;
        if ($owner !== null) {
            $brandUser = (new BrandUser())->setUser($owner)->setRole(BrandUser::ROLE_OWNER);
        }
        $repo->method('findOneBy')->willReturn($brandUser);

        return [new ModerationOwnerNotifier($dispatcher, $repo, $notifications), $dispatcher];
    }

    private function moderation(string $status, array $missing = [], array $flags = []): BrandModeration
    {
        $moderation = new BrandModeration();
        $moderation->setStatus($status);
        $moderation->setMissing($missing);
        $moderation->setRedFlags($flags);

        return $moderation;
    }

    public function testChangesRequestedTellsOwnerWhatIsMissing(): void
    {
        [$notifier, $dispatcher] = $this->notifierFor((new User())->setEmail('owner@brand.ru'));
        $brand = (new Brand())->setTitle('АХ!');

        $dispatcher->expects($this->once())->method('dispatch')->with(
            $this->anything(),
            Notification::TYPE_SYSTEM,
            $this->stringContains('нужно дополнить карточку'),
            $this->stringContains('ИНН'),
            $this->anything(),
            'brand_moderation_result',
        );

        $notifier->notify($brand, $this->moderation(BrandModeration::STATUS_CHANGES_REQUESTED, ['inn', 'price']));
    }

    public function testApprovedNotifiesOwner(): void
    {
        [$notifier, $dispatcher] = $this->notifierFor((new User())->setEmail('owner@brand.ru'));
        $dispatcher->expects($this->once())->method('dispatch');

        $notifier->notify(new Brand(), $this->moderation(BrandModeration::STATUS_APPROVED));
    }

    public function testUndecidedStatusesStaySilent(): void
    {
        [$notifier, $dispatcher] = $this->notifierFor((new User())->setEmail('owner@brand.ru'));
        $dispatcher->expects($this->never())->method('dispatch');

        $notifier->notify(new Brand(), $this->moderation(BrandModeration::STATUS_QUEUED));
        $notifier->notify(new Brand(), $this->moderation(BrandModeration::STATUS_REVIEWED));
    }

    public function testRepeatedClickDoesNotNotifyTwice(): void
    {
        // Кнопки в TG бессрочные; уникальный (recipient, dedupe_key) уронил бы flush
        // вместе с записью решения.
        [$notifier, $dispatcher] = $this->notifierFor((new User())->setEmail('owner@brand.ru'), alreadyNotified: true);
        $dispatcher->expects($this->never())->method('dispatch');

        $notifier->notify(new Brand(), $this->moderation(BrandModeration::STATUS_CHANGES_REQUESTED, ['inn']));
    }

    public function testCatalogBrandWithoutOwnerIsSkipped(): void
    {
        [$notifier, $dispatcher] = $this->notifierFor(null);
        $dispatcher->expects($this->never())->method('dispatch');

        $notifier->notify(new Brand(), $this->moderation(BrandModeration::STATUS_APPROVED));
    }

    public function testLabelsTranslateCodesAndKeepUnknownOnes(): void
    {
        $labels = ModerationLabels::missing(['inn', 'logo', 'wat']);
        $this->assertStringContainsString('ИНН', $labels[0]);
        $this->assertStringContainsString('логотип', $labels[1]);
        $this->assertSame('wat', $labels[2]);

        $this->assertSame([], ModerationLabels::missing(null));
        $this->assertStringContainsString('скриншот', ModerationLabels::flags(['logo_is_screenshot'])[0]);
    }
}
