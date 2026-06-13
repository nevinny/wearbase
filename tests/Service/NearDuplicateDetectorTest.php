<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NearDuplicateDetector;
use PHPUnit\Framework\TestCase;

class NearDuplicateDetectorTest extends TestCase
{
    private NearDuplicateDetector $d;

    protected function setUp(): void
    {
        $this->d = new NearDuplicateDetector();
    }

    public function testIdenticalIsOne(): void
    {
        $t = 'Бренд одежды из Москвы выпускает базовый гардероб для города и повседневной носки';
        self::assertSame(1.0, $this->d->similarity($t, $t));
    }

    public function testDistinctIsZero(): void
    {
        $a = 'Бренд одежды из Москвы выпускает базовый гардероб для города и повседневной носки';
        $b = 'Петербургская марка делает вечерние платья ручной работы из натурального шёлка';
        self::assertSame(0.0, $this->d->similarity($a, $b));
    }

    public function testNearDuplicateAboveDropThreshold(): void
    {
        // одно описание, заменены 2-3 слова — классический scaled-content
        $a = 'Российский бренд уличной одежды основан в 2015 году делает худи свитшоты и брюки из плотного хлопка';
        $b = 'Российский бренд уличной одежды основан в 2015 году делает худи свитшоты и брюки из плотного денима';
        $score = $this->d->similarity($a, $b);
        self::assertGreaterThanOrEqual(NearDuplicateDetector::WARN_THRESHOLD, $score);
    }

    public function testEmptyTextYieldsZero(): void
    {
        self::assertSame(0.0, $this->d->similarity('', 'что-то непустое тут есть слова'));
    }

    public function testNearestPicksHighestScoringId(): void
    {
        $target = $this->d->shingles('красные кеды на белой подошве из натуральной кожи ручная работа');
        $corpus = [
            10 => $this->d->shingles('вечернее платье из шёлка с открытой спиной для торжества'),
            20 => $this->d->shingles('красные кеды на белой подошве из натуральной кожи ручная работа'),
            30 => $this->d->shingles('зимняя куртка пуховик с капюшоном на синтепоне тёплая'),
        ];
        $near = $this->d->nearest($target, $corpus);
        self::assertSame(20, $near['id']);
        self::assertSame(1.0, $near['score']);
    }

    public function testJaccardSymmetric(): void
    {
        $a = $this->d->shingles('первый второй третий четвёртый пятый шестой седьмой');
        $b = $this->d->shingles('первый второй третий восьмой девятый десятый');
        self::assertSame($this->d->jaccard($a, $b), $this->d->jaccard($b, $a));
    }
}
