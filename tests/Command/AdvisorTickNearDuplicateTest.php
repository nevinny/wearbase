<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AdvisorTickCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Заголовки — реальные proposed-идеи из advisor_idea (Mac, 2026-11-23). */
class AdvisorTickNearDuplicateTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function pairs(): iterable
    {
        yield 'аудит форм: воронка vs все формы' => ['Аудит воронки захвата лидов (Check-form)', 'Аудит работоспособности всех форм захвата и систем уведомлений', true];
        yield 'аудит форм: лидогенерация vs кнопки' => ['Аудит работоспособности форм захвата и лидогенерации', 'Аудит работоспособности форм захвата и кнопок заказа', true];
        yield 'дрип: латиница в скобках ≈ кириллица' => ['Запуск процесса публикации из очереди (Back to Drip)', 'Возобновление и автоматизация дрипа публикаций', true];
        yield 'дрип: Fix Drip vs восстановление' => ['Возобновление процесса публикации (Fix Drip)', 'Восстановление дрип-публикаций брендов', true];
        yield 'разные идеи: дрип vs аудит форм' => ['Возобновление и автоматизация дрипа публикаций', 'Аудит воронки захвата лидов (Check-form)', false];
        yield 'разные идеи: отклик лидам vs аудит форм' => ['Внедрение системы быстрого отклика для лидов', 'Аудит работоспособности форм захвата и кнопок заказа', false];
        yield 'одно общее слово — не дубль' => ['Аудит карточек брендов', 'Аудит рассылки', false];
    }

    #[DataProvider('pairs')]
    public function testNearDuplicate(string $a, string $b, bool $expected): void
    {
        $this->assertSame($expected, AdvisorTickCommand::isNearDuplicateTitle($a, $b));
        $this->assertSame($expected, AdvisorTickCommand::isNearDuplicateTitle($b, $a), 'симметрично');
    }
}
