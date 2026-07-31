<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Brand;
use App\Entity\SocialPost;
use App\Service\Social\GallerySlideRenderer;
use App\Service\Social\SlideScript;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Нормализация слайдов и позиция логотипа — сердце A/B: если холст не один, Instagram
 * кадрирует карусель по первому слайду и эксперимент сравнивает не то, что задумано.
 */
class GallerySlideRendererTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/wb-slides-' . getmypid();
        @mkdir($this->projectDir . '/public_html/images/brands', 0775, true);
        @mkdir($this->projectDir . '/public_html/images/logos', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->projectDir);
    }

    public function testSlidesNormalizedToSingleCanvas(): void
    {
        // Разные пропорции: горизонталь, вертикаль, квадрат.
        $this->makeImage('/public_html/images/brands/a.jpg', 1600, 900);
        $this->makeImage('/public_html/images/brands/b.jpg', 600, 1400);
        $this->makeImage('/public_html/images/brands/c.jpg', 800, 800);

        $slides = $this->renderer()->render(
            $this->post(1, brandWithLogo: false),
            ['/images/brands/a.jpg', '/images/brands/b.jpg', '/images/brands/c.jpg'],
            logoFirst: false,
        );

        self::assertCount(3, $slides);
        foreach ($slides as $slide) {
            $size = getimagesize($this->projectDir . '/public_html' . $slide);
            self::assertSame([GallerySlideRenderer::WIDTH, GallerySlideRenderer::HEIGHT], [$size[0], $size[1]], $slide);
        }
    }

    public function testLogoSlideGoesFirstOrLastByVariant(): void
    {
        $this->makeImage('/public_html/images/brands/a.jpg', 1000, 1000);
        $this->makeImage('/public_html/images/brands/b.jpg', 1000, 1000);
        $this->makeImage('/public_html/images/logos/logo.jpg', 400, 200);

        $first = $this->renderer()->render($this->post(10), ['/images/brands/a.jpg', '/images/brands/b.jpg'], logoFirst: true);
        $last = $this->renderer()->render($this->post(11), ['/images/brands/a.jpg', '/images/brands/b.jpg'], logoFirst: false);

        self::assertCount(3, $first);
        self::assertCount(3, $last);
        self::assertStringContainsString('-logo.jpg', $first[0]);
        self::assertStringContainsString('-logo.jpg', $last[2]);
        // Фото в обеих ветках идут в одном порядке — различается только позиция логотипа.
        self::assertSame(['p10-01.jpg', 'p10-02.jpg'], array_map('basename', [$first[1], $first[2]]));
        self::assertSame(['p11-01.jpg', 'p11-02.jpg'], array_map('basename', [$last[0], $last[1]]));
    }

    public function testMissingSourcesAndLogoDegradeGracefully(): void
    {
        $this->makeImage('/public_html/images/brands/a.jpg', 900, 1100);

        // Логотипа на диске нет → слайд логотипа просто не добавляется.
        $slides = $this->renderer()->render($this->post(20), ['/images/brands/a.jpg', '/images/brands/gone.jpg'], logoFirst: true);

        self::assertCount(1, $slides);
        self::assertStringContainsString('p20-01.jpg', $slides[0]);
    }

    /**
     * Надписи сценария встают на первый / средний / последний кадр — и в обеих ветках A/B на
     * одни и те же ПОЗИЦИИ. Совпадение самих слов гарантирует композер (у него на входе нет
     * ветки, см. SlideScriptComposerTest), здесь проверяется геометрия раскладки.
     *
     */
    #[DataProvider('variants')]
    public function testScriptTextLandsOnFirstMiddleAndLastSlide(int $postId, bool $logoFirst): void
    {
        $sources = [];
        foreach (range(1, 5) as $i) {
            $this->makeImage("/public_html/images/brands/s{$i}.jpg", 900, 1100);
            $sources[] = "/images/brands/s{$i}.jpg";
        }
        $this->makeImage('/public_html/images/logos/logo.jpg', 400, 200);

        $slides = $this->renderer()->render($this->post($postId), $sources, $logoFirst, $this->script());

        // 5 фото + логотип = 6 слайдов, середина — индекс 3.
        self::assertCount(6, $slides);
        self::assertSame("p{$postId}-hook.jpg", basename($slides[0]));
        self::assertSame("p{$postId}-mid.jpg", basename($slides[3]));
        self::assertSame("p{$postId}-cta.jpg", basename($slides[5]));

        // Холст у слайдов с надписями тот же — иначе Instagram кадрирует карусель иначе.
        foreach ($slides as $slide) {
            $size = getimagesize($this->projectDir . '/public_html' . $slide);
            self::assertSame([GallerySlideRenderer::WIDTH, GallerySlideRenderer::HEIGHT], [$size[0], $size[1]], $slide);
        }

        // Обложка Reels — чистое первое фото: надписи пишутся в отдельные файлы, поэтому
        // p{id}-01.jpg остаётся без текста и обложка у ветвей A/B одинаковая.
        $cover = $this->renderer()->coverSlide($this->post($postId));
        self::assertSame("/images/social/gallery/p{$postId}-01.jpg", $cover);
        self::assertNotSame($cover, $slides[0], 'Обложка не должна быть кадром с надписью');
    }

    /** @return iterable<string, array{int, bool}> */
    public static function variants(): iterable
    {
        yield 'logo_last'  => [30, false];
        yield 'logo_first' => [31, true];
    }

    /** Короткая последовательность обходится без реплики в середине — иначе стена текста. */
    public function testShortSequenceHasNoRetentionLine(): void
    {
        $this->makeImage('/public_html/images/brands/a.jpg', 900, 1100);
        $this->makeImage('/public_html/images/brands/b.jpg', 900, 1100);
        $this->makeImage('/public_html/images/logos/logo.jpg', 400, 200);

        $slides = $this->renderer()->render($this->post(40), ['/images/brands/a.jpg', '/images/brands/b.jpg'], false, $this->script());

        self::assertCount(3, $slides);
        self::assertSame('p40-hook.jpg', basename($slides[0]));
        self::assertSame('p40-cta.jpg', basename($slides[2]));
        self::assertFileDoesNotExist($this->projectDir . '/public_html/images/social/gallery/p40-mid.jpg');
    }

    /**
     * Логотипа на диске нет → слайд логотипа не рисуется, но хук и CTA всё равно должны
     * оказаться на первом и последнем кадре (раньше в ветке logo_first хук уезжал вместе с
     * логотипом, и пост уходил в раздачу вообще без первой надписи).
     */
    public function testMissingLogoStillLeavesHookAndCta(): void
    {
        $this->makeImage('/public_html/images/brands/a.jpg', 900, 1100);
        $this->makeImage('/public_html/images/brands/b.jpg', 900, 1100);

        $slides = $this->renderer()->render($this->post(50), ['/images/brands/a.jpg', '/images/brands/b.jpg'], true, $this->script());

        self::assertCount(2, $slides);
        self::assertSame('p50-hook.jpg', basename($slides[0]));
        self::assertSame('p50-cta.jpg', basename($slides[1]));
    }

    private function script(): SlideScript
    {
        return new SlideScript(
            hook: "Пермь. 5 кадров\nСколько тут твоего?",
            retention: "Считай, не листай.\nВ конце — куда писать.",
            ctaLines: ['Сколько? Пиши цифру.', 'Сохрани, чтобы не потерять.', 'Отправь тому, кто ищет такое.'],
        );
    }

    private function renderer(): GallerySlideRenderer
    {
        return new GallerySlideRenderer($this->projectDir, __DIR__ . '/../../../config/social/fonts/NotoSans.ttf');
    }

    private function post(int $id, bool $brandWithLogo = true): SocialPost
    {
        $brand = (new Brand())->setTitle('Тест');
        if ($brandWithLogo) {
            $brand->setLogo('logo.jpg');
        }

        $post = (new SocialPost())->setBrand($brand);

        // id проставляется БД — в юнит-тесте задаём рефлексией, он нужен для имён файлов.
        $ref = new \ReflectionProperty(SocialPost::class, 'id');
        $ref->setValue($post, $id);

        return $post;
    }

    private function makeImage(string $relPath, int $w, int $h): void
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 120, 130, 140));
        imagejpeg($im, $this->projectDir . $relPath, 80);
        imagedestroy($im);
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
