<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\WardrobeMemoryFact;
use PHPUnit\Framework\TestCase;

final class WardrobeMemoryFactTest extends TestCase
{
    public function testManualEditSurvivesSourceRefreshAndDeleteIsSoft(): void
    {
        $user = new User();
        $fact = new WardrobeMemoryFact($user, $user, WardrobeMemoryFact::SOURCE_WEAR, 10, 'self', 'Первоначальный факт');

        $fact->edit("  Исправленный\nфакт  ");
        $fact->refresh('Автоматическое обновление');

        self::assertSame('Исправленный факт', $fact->getFact());
        self::assertNotNull($fact->getEditedAt());
        $fact->delete();
        self::assertTrue($fact->isDeleted());
        $fact->refresh('Нельзя воскресить автоматически');
        self::assertTrue($fact->isDeleted());
        self::assertSame('[deleted]', $fact->getFact());
    }

    public function testRejectsEmptyOrUnboundedFact(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WardrobeMemoryFact(new User(), new User(), WardrobeMemoryFact::SOURCE_WEAR, 1, 'self', str_repeat('я', 501));
    }
}
