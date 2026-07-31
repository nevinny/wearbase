<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\ReelsSlideshowRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Выбор фонового трека для Reels: детерминизм по id поста + мягкая деградация на тишину,
 * если библиотека `config/social/audio` пуста или отсутствует (проверяется через private
 * selectTrack — публичный render() гоняет реальный ffmpeg, это покрыто ручной проверкой).
 */
class ReelsSlideshowRendererTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/wb-reels-audio-' . getmypid();
        @mkdir($this->projectDir . '/config/social/audio', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->projectDir);
    }

    public function testSameIdAlwaysSelectsSameTrack(): void
    {
        $this->makeTrack('a.m4a');
        $this->makeTrack('b.m4a');
        $this->makeTrack('c.m4a');

        $renderer = new ReelsSlideshowRenderer($this->projectDir);

        self::assertSame($this->selectTrack($renderer, 42), $this->selectTrack($renderer, 42));
    }

    public function testDifferentIdsCanSelectDifferentTracks(): void
    {
        $this->makeTrack('a.m4a');
        $this->makeTrack('b.m4a');
        $this->makeTrack('c.m4a');

        $renderer = new ReelsSlideshowRenderer($this->projectDir);

        // 3 трека, сортировка по имени детерминирует индекс: id % 3.
        $tracks = [
            $this->selectTrack($renderer, 0),
            $this->selectTrack($renderer, 1),
            $this->selectTrack($renderer, 2),
        ];

        self::assertSame(['a.m4a', 'b.m4a', 'c.m4a'], array_map('basename', $tracks));
        self::assertSame($tracks[0], $this->selectTrack($renderer, 3), 'id 3 должен вернуться к тому же треку, что id 0 (3 % 3 = 0)');
    }

    public function testMixedExtensionsAreBothPickedUp(): void
    {
        $this->makeTrack('one.mp3');
        $this->makeTrack('two.m4a');

        $renderer = new ReelsSlideshowRenderer($this->projectDir);

        self::assertNotNull($this->selectTrack($renderer, 0));
        self::assertNotNull($this->selectTrack($renderer, 1));
    }

    /**
     * Развязка (последний кадр) читается за 3.0с, не 1.5с — три строки текста (имя, город/
     * категории, просьба сохранить) за темп остальных кадров не прочитать. Итоговая
     * длительность: (N−1)×1.5 + 3.0.
     */
    #[DataProvider('slideCounts')]
    public function testLastSlideGetsLongerDuration(int $slideCount): void
    {
        $dir = $this->projectDir . '/public_html/images/social/gallery';
        @mkdir($dir, 0775, true);

        $paths = [];
        for ($i = 1; $i <= $slideCount; $i++) {
            $name = "p1-{$i}.jpg";
            file_put_contents($dir . '/' . $name, 'x');
            $paths[] = '/images/social/gallery/' . $name;
        }

        $renderer = new ReelsSlideshowRenderer($this->projectDir);
        $method = new \ReflectionMethod($renderer, 'planSlides');
        $result = $method->invoke($renderer, $paths);

        self::assertNotNull($result);
        self::assertEqualsWithDelta(($slideCount - 1) * 1.5 + 3.0, $result['duration'], 0.001);
        self::assertCount($slideCount, $result['slides']);
        self::assertSame(3.0, $result['slides'][$slideCount - 1]['seconds'], 'развязка держится LAST_SLIDE_SECONDS');
        if ($slideCount > 1) {
            self::assertSame(1.5, $result['slides'][0]['seconds']);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function slideCounts(): iterable
    {
        yield '1 слайд' => [1];
        yield '2 слайда' => [2];
        yield '7 слайдов' => [7];
    }

    public function testEmptyLibraryFallsBackToNull(): void
    {
        // Каталог существует, но пуст.
        $renderer = new ReelsSlideshowRenderer($this->projectDir);

        self::assertNull($this->selectTrack($renderer, 7));
    }

    public function testMissingAudioDirFallsBackToNull(): void
    {
        $this->rmrf($this->projectDir . '/config/social/audio');

        $renderer = new ReelsSlideshowRenderer($this->projectDir);

        self::assertNull($this->selectTrack($renderer, 7));
    }

    private function selectTrack(ReelsSlideshowRenderer $renderer, int $postId): ?string
    {
        $method = new \ReflectionMethod($renderer, 'selectTrack');

        return $method->invoke($renderer, $postId);
    }

    private function makeTrack(string $name): void
    {
        // Содержимое не важно — selectTrack только сканирует имена файлов по расширению.
        file_put_contents($this->projectDir . '/config/social/audio/' . $name, 'x');
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
