<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PublishTickJitter;
use PHPUnit\Framework\TestCase;

class PublishTickJitterTest extends TestCase
{
    private function at(string $date): \DateTime
    {
        return new \DateTime($date, new \DateTimeZone('Europe/Moscow'));
    }

    public function testFirstTickOfHourSchedulesPublishAtWithinCurrentHour(): void
    {
        $jitter = new PublishTickJitter();
        $now = $this->at('2026-08-30 09:00:00');

        $result = $jitter->evaluate($now, null, static fn () => ['n' => 3]);

        self::assertFalse($result['publish'], 'публикация решается сразу только если случайная задержка выпала 0');
        self::assertSame('2026-08-30 09', $result['state']['hour']);
        self::assertSame(3, $result['state']['n']);

        $hourStart = $this->at('2026-08-30 09:00:00')->getTimestamp();
        $hourEnd   = $this->at('2026-08-30 09:45:00')->getTimestamp();
        self::assertGreaterThanOrEqual($hourStart, $result['state']['publish_at'], 'намеченная минута не раньше начала часа');
        self::assertLessThanOrEqual($hourEnd, $result['state']['publish_at'], 'намеченная минута в пределах 45 мин (MAX_DELAY_SECONDS)');
    }

    public function testDoesNotPublishBeforeScheduledMinute(): void
    {
        $jitter = new PublishTickJitter();
        $state  = ['hour' => '2026-08-30 09', 'publish_at' => $this->at('2026-08-30 09:30:00')->getTimestamp(), 'done' => false, 'n' => 3];

        $result = $jitter->evaluate($this->at('2026-08-30 09:10:00'), $state, static fn () => self::fail('onNewHour не должен звонить в том же часу'));

        self::assertFalse($result['publish']);
        self::assertFalse($result['state']['done']);
    }

    public function testPublishesOnceWhenScheduledMinuteArrives(): void
    {
        $jitter = new PublishTickJitter();
        $state  = ['hour' => '2026-08-30 09', 'publish_at' => $this->at('2026-08-30 09:30:00')->getTimestamp(), 'done' => false, 'n' => 3];

        $result = $jitter->evaluate($this->at('2026-08-30 09:30:00'), $state, static fn () => self::fail('onNewHour не должен звонить в том же часу'));

        self::assertTrue($result['publish']);
        self::assertTrue($result['state']['done']);
        self::assertSame(3, $result['state']['n']);
    }

    public function testRepeatedTickInSameHourDoesNotPublishAgain(): void
    {
        $jitter = new PublishTickJitter();
        $state  = ['hour' => '2026-08-30 09', 'publish_at' => $this->at('2026-08-30 09:30:00')->getTimestamp(), 'done' => true, 'n' => 3];

        $result = $jitter->evaluate($this->at('2026-08-30 09:40:00'), $state, static fn () => self::fail('onNewHour не должен звонить в том же часу'));

        self::assertFalse($result['publish']);
        self::assertTrue($result['state']['done']);
    }

    public function testLateFirstTickCatchesUpImmediatelyIfMinuteAlreadyPassed(): void
    {
        // Первый тик часа опоздал (глобальный лок держал предыдущий проход) и пришёлся уже
        // на 09:50 — намеченная минута (0..45 от начала часа) гарантированно в прошлом.
        $jitter = new PublishTickJitter();
        $result = $jitter->evaluate($this->at('2026-08-30 09:50:00'), null, static fn () => ['n' => 2]);

        self::assertTrue($result['publish']);
        self::assertTrue($result['state']['done']);
    }

    public function testNewHourResetsState(): void
    {
        $jitter = new PublishTickJitter();
        $prevHourState = ['hour' => '2026-08-30 09', 'publish_at' => $this->at('2026-08-30 09:30:00')->getTimestamp(), 'done' => true, 'n' => 3];

        $result = $jitter->evaluate($this->at('2026-08-30 10:00:00'), $prevHourState, static fn () => ['n' => 4]);

        self::assertSame('2026-08-30 10', $result['state']['hour']);
        self::assertSame(4, $result['state']['n']);
        self::assertFalse($result['state']['done']);
    }

    public function testOnNewHourCanSkipEmptyHourWithoutWaiting(): void
    {
        $jitter = new PublishTickJitter();

        $result = $jitter->evaluate($this->at('2026-08-30 09:00:00'), null, static fn () => ['n' => 0, 'done' => true]);

        self::assertFalse($result['publish'], 'нечего публиковать (n=0) — час пропущен без ожидания');
        self::assertTrue($result['state']['done']);
        self::assertSame(0, $result['state']['n']);
    }

    public function testImmediatePublishesRightAwayIgnoringJitter(): void
    {
        $jitter = new PublishTickJitter();

        $result = $jitter->evaluate($this->at('2026-08-30 09:00:00'), null, static fn () => ['n' => 5], immediate: true);

        self::assertTrue($result['publish']);
        self::assertTrue($result['state']['done']);
        self::assertSame(5, $result['state']['n']);
    }

    public function testImmediateRecalculatesPlanEvenIfHourAlreadyDone(): void
    {
        $jitter = new PublishTickJitter();
        // Час уже отработан и n «догорел» до нуля — ручной прогон обязан посчитать заново,
        // иначе `--now` в runbook молча ничего не публикует.
        $state  = ['hour' => '2026-08-30 09', 'publish_at' => $this->at('2026-08-30 09:30:00')->getTimestamp(), 'done' => true, 'n' => 0];

        $result = $jitter->evaluate($this->at('2026-08-30 09:40:00'), $state, static fn () => ['n' => 4], immediate: true);

        self::assertTrue($result['publish'], '--now публикует поверх уже отработанного часа (ручной прогон/отладка)');
        self::assertSame(4, $result['state']['n'], 'план пересчитан, а не взят из отработанного часа');
        self::assertTrue($result['state']['done']);
    }
}
