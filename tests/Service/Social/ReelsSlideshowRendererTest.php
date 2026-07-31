<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\ReelsSlideshowRenderer;
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
