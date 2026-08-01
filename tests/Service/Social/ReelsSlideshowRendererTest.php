<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\GallerySlideRenderer;
use App\Service\Social\ReelsSlideshowRenderer;
use App\Service\Social\SlideScript;
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

        $renderer = $this->renderer();

        self::assertSame($this->selectTrack($renderer, 42), $this->selectTrack($renderer, 42));
    }

    public function testDifferentIdsCanSelectDifferentTracks(): void
    {
        $this->makeTrack('a.m4a');
        $this->makeTrack('b.m4a');
        $this->makeTrack('c.m4a');

        $renderer = $this->renderer();

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

        $renderer = $this->renderer();

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

        $renderer = $this->renderer();
        $method = new \ReflectionMethod($renderer, 'planSlides');
        $result = $method->invoke($renderer, $paths, 1, SlideScript::PROFILE_FLAT);

        self::assertNotNull($result);
        self::assertEqualsWithDelta(($slideCount - 1) * 1.5 + 3.0, $result['duration'], 0.001);
        self::assertCount($slideCount, $result['slides']);
        self::assertSame(3.0, $result['slides'][$slideCount - 1]['seconds'], 'развязка держится LAST_SLIDE_SECONDS');
        if ($slideCount > 1) {
            self::assertSame(1.5, $result['slides'][0]['seconds']);
        }
        // Ни clean-фото, ни оверлей для этих фикстур не рендерились (нет GallerySlideRenderer::
        // render() перед тестом) — деградация на сам baked-файл, без слоя UI.
        foreach ($result['slides'] as $i => $slide) {
            self::assertSame($paths[$i], '/images/social/gallery/' . basename($slide['file']));
            self::assertNull($slide['overlay']);
        }
    }

    /**
     * Когда GallerySlideRenderer успел отрендерить чистый фото-слой и оверлей позиции (обычный
     * путь для новых постов), planSlides() берёт ИХ, а не baked-файл со вжаренным UI — иначе
     * счётчик/прогресс/текст зумились бы вместе с фото (дефект, который и чинит разделение
     * слоёв).
     */
    public function testPlanSlidesPrefersCleanAndOverlayLayersWhenBothExist(): void
    {
        $dir = $this->projectDir . '/public_html/images/social/gallery';
        @mkdir($dir, 0775, true);

        $slideRenderer = new GallerySlideRenderer($this->projectDir, __DIR__ . '/../../../config/social/fonts/NotoSans.ttf');

        file_put_contents($dir . '/p9-01.jpg', 'baked');
        file_put_contents($this->projectDir . '/public_html' . $slideRenderer->reelsCleanPhotoPath(9, 1), 'clean');
        file_put_contents($this->projectDir . '/public_html' . $slideRenderer->reelsOverlayPath(9, 1), 'overlay');

        $renderer = new ReelsSlideshowRenderer($this->projectDir, $slideRenderer);
        $method = new \ReflectionMethod($renderer, 'planSlides');
        $result = $method->invoke($renderer, ['/images/social/gallery/p9-01.jpg'], 9, SlideScript::PROFILE_FLAT);

        self::assertNotNull($result);
        self::assertStringEndsWith('p9-clean-01.jpg', $result['slides'][0]['file']);
        self::assertStringEndsWith('p9-ovl-01.png', $result['slides'][0]['overlay']);
    }

    /** @return iterable<string, array{int}> */
    public static function slideCounts(): iterable
    {
        yield '1 слайд' => [1];
        yield '2 слайда' => [2];
        yield '7 слайдов' => [7];
    }

    // --- P0-1: профиль hook_hold (§3.1 плейбука) ----------------------------------------------

    /**
     * Профиль А на 9 слайдов (раскладка из §3.1 плейбука): хук 3.0с, слайды 2-4 по 1.5с,
     * слайды 5-8 по 1.1с, развязка (слайд 9) 3.0с.
     */
    public function testHookHoldProfileMatchesPlaybookLayoutForNineSlides(): void
    {
        $seconds = ReelsSlideshowRenderer::slideSeconds(9, SlideScript::PROFILE_HOOK_HOLD);

        self::assertSame([3.0, 1.5, 1.5, 1.5, 1.1, 1.1, 1.1, 1.1, 3.0], $seconds);
    }

    /** flat_150 — ровный метроном (контрольная ветка E1), последний слайд всё равно развязка. */
    public function testFlatProfileIsUniformExceptFinale(): void
    {
        $seconds = ReelsSlideshowRenderer::slideSeconds(5, SlideScript::PROFILE_FLAT);

        self::assertSame([1.5, 1.5, 1.5, 1.5, 3.0], $seconds);
    }

    /** На коротких сценариях (3 слайда) слайды 2-4 не выходят за пределы N — только слайд 2 попадает под 1.5с. */
    public function testHookHoldProfileOnShortScriptDoesNotOverrunTotal(): void
    {
        $seconds = ReelsSlideshowRenderer::slideSeconds(3, SlideScript::PROFILE_HOOK_HOLD);

        self::assertSame([3.0, 1.5, 3.0], $seconds);
    }

    public function testTotalSecondsIsSumOfSlideSeconds(): void
    {
        self::assertEqualsWithDelta(16.0, ReelsSlideshowRenderer::totalSeconds(10, SlideScript::PROFILE_HOOK_HOLD), 0.001);
        self::assertEqualsWithDelta(16.5, ReelsSlideshowRenderer::totalSeconds(10, SlideScript::PROFILE_FLAT), 0.001);
    }

    /** planSlides() читает профиль из script_json поста (durationsProfile), не свой параметр по умолчанию. */
    public function testPlanSlidesUsesHookHoldProfileWhenRequested(): void
    {
        $dir = $this->projectDir . '/public_html/images/social/gallery';
        @mkdir($dir, 0775, true);

        $paths = [];
        for ($i = 1; $i <= 5; $i++) {
            $name = "p2-{$i}.jpg";
            file_put_contents($dir . '/' . $name, 'x');
            $paths[] = '/images/social/gallery/' . $name;
        }

        $renderer = $this->renderer();
        $method = new \ReflectionMethod($renderer, 'planSlides');
        $result = $method->invoke($renderer, $paths, 2, SlideScript::PROFILE_HOOK_HOLD);

        self::assertNotNull($result);
        self::assertSame([3.0, 1.5, 1.5, 1.5, 3.0], array_column($result['slides'], 'seconds'));
    }

    // --- P0-6: кап длины клипа 38с (§6 п.5 / §9 №6 плейбука) -----------------------------------

    /**
     * Шов трека в `config/social/audio` ровно на 40.000с при `-stream_loop -1` — суммарная
     * длительность клипа выше 38с обязана падать исключением, а не тихо собираться со швом.
     */
    public function testPlanSlidesThrowsWhenDurationExceedsCap(): void
    {
        $dir = $this->projectDir . '/public_html/images/social/gallery';
        @mkdir($dir, 0775, true);

        // flat_150 на 30 слайдов: 29×1.5+3.0 = 46.5с — заведомо выше кап 38с.
        $paths = [];
        for ($i = 1; $i <= 30; $i++) {
            $name = "p3-{$i}.jpg";
            file_put_contents($dir . '/' . $name, 'x');
            $paths[] = '/images/social/gallery/' . $name;
        }

        $renderer = $this->renderer();
        $method = new \ReflectionMethod($renderer, 'planSlides');

        $this->expectException(\RuntimeException::class);
        $method->invoke($renderer, $paths, 3, SlideScript::PROFILE_FLAT);
    }

    public function testEmptyLibraryFallsBackToNull(): void
    {
        // Каталог существует, но пуст.
        $renderer = $this->renderer();

        self::assertNull($this->selectTrack($renderer, 7));
    }

    public function testMissingAudioDirFallsBackToNull(): void
    {
        $this->rmrf($this->projectDir . '/config/social/audio');

        $renderer = $this->renderer();

        self::assertNull($this->selectTrack($renderer, 7));
    }

    private function renderer(): ReelsSlideshowRenderer
    {
        $fontPath = __DIR__ . '/../../../config/social/fonts/NotoSans.ttf';

        return new ReelsSlideshowRenderer($this->projectDir, new GallerySlideRenderer($this->projectDir, $fontPath));
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
