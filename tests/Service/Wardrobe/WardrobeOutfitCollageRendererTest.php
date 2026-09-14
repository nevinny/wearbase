<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\WardrobeItem;
use App\Entity\WardrobeOutfit;
use App\Service\Wardrobe\WardrobeOutfitCollageRenderer;
use PHPUnit\Framework\TestCase;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Композиция коллажа: «высокие» вещи (верх/низ/платья/верхняя одежда) идут в сетку сверху,
 * «компактные» (обувь/сумки/аксессуары) — отдельной строкой снизу, раскладка меняется по
 * числу вещей (2..5), вещь без фото не ломает рендер, повторный вызов не перезаписывает файл.
 */
final class WardrobeOutfitCollageRendererTest extends TestCase
{
    private string $projectDir;
    /** @var array<int, ?string> spl_object_id(WardrobeItem) => путь к фото или null (нет фото) */
    private array $photoPaths = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/wb-outfit-collage-' . getmypid() . '-' . uniqid('', true);
        $this->photoPaths = [];
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->projectDir);
    }

    public function testTwoItemsPutTallOnTopAndCompactInBottomStrip(): void
    {
        $dress = $this->item(1, 'Платье', $this->solidImage(900, 1200, 220, 20, 20)); // красный, портретное фото
        $sneakers = $this->item(2, 'Кроссовки', $this->solidImage(1000, 700, 20, 20, 220)); // синий, landscape-фото

        $path = $this->renderer()->render($this->outfit(101), [$dress, $sneakers]);

        self::assertNotNull($path);
        self::assertFileExists($path);
        [$w, $h] = getimagesize($path);
        self::assertSame([WardrobeOutfitCollageRenderer::WIDTH, WardrobeOutfitCollageRenderer::HEIGHT], [$w, $h]);

        $canvas = imagecreatefromjpeg($path);
        // Верхняя область (грид «высоких» вещей) — платье, нижняя строка — кроссовки. Кроп
        // заполняет ячейку целиком (без полей) — координаты центров см. layout()/row().
        $this->assertPixelCloseTo($canvas, 540, 555, [220, 20, 20]);
        $this->assertPixelCloseTo($canvas, 540, 1168, [20, 20, 220]);
        imagedestroy($canvas);
    }

    /**
     * Без единой компактной вещи (нет строки снизу) — 2 «высокие» вещи бок о бок на всю ширину
     * холста, каждая колонка заполнена фото целиком (cover, без пустых полей по бокам).
     */
    public function testTwoTallItemsWithoutCompactSitSideBySideFillingFullWidth(): void
    {
        $left = $this->item(1, 'Худи', $this->solidImage(900, 1200, 220, 20, 20));
        $right = $this->item(2, 'Брюки', $this->solidImage(900, 1200, 20, 20, 220));

        $path = $this->renderer()->render($this->outfit(107), [$left, $right]);

        self::assertNotNull($path);
        $canvas = imagecreatefromjpeg($path);
        // Центр каждой колонки — цвет вещи; края холста (у самой рамки) — тоже вещь, не белое
        // поле (главная жалоба ревью: раньше две вещи занимали меньше половины ширины).
        $this->assertPixelCloseTo($canvas, 278, 721, [220, 20, 20]);
        $this->assertPixelCloseTo($canvas, 802, 721, [20, 20, 220]);
        $this->assertPixelCloseTo($canvas, 26, 721, [220, 20, 20], 'левый край холста должен быть занят фото, не белым полем');
        $this->assertPixelCloseTo($canvas, 1054, 721, [20, 20, 220], 'правый край холста должен быть занят фото, не белым полем');
        imagedestroy($canvas);
    }

    public function testFiveItemsSplitThreeTallRowAndTwoCompactRow(): void
    {
        $items = [
            $this->item(1, 'Куртка', $this->solidImage(800, 1100, 255, 0, 0)),
            $this->item(2, 'Футболка', $this->solidImage(800, 1100, 0, 255, 0)),
            $this->item(3, 'Брюки', $this->solidImage(800, 1100, 0, 0, 255)),
            $this->item(4, 'Кроссовки', $this->solidImage(1000, 700, 255, 255, 0)),
            $this->item(5, 'Сумка', $this->solidImage(900, 900, 255, 0, 255)),
        ];

        $path = $this->renderer()->render($this->outfit(102), $items);
        self::assertNotNull($path);
        $canvas = imagecreatefromjpeg($path);

        // «Высокие» вещи — максимум 2 колонки в ряду (см. докблок grid()): куртка/футболка
        // сверху рядом, брюки — во втором ряду одни на всю ширину (не узкой колонкой по центру).
        $this->assertPixelCloseTo($canvas, 278, 332, [255, 0, 0]);
        $this->assertPixelCloseTo($canvas, 802, 332, [0, 255, 0]);
        $this->assertPixelCloseTo($canvas, 540, 779, [0, 0, 255]);
        $this->assertPixelCloseTo($canvas, 26, 779, [0, 0, 255], 'одинокая вещь в ряду должна занимать всю ширину, не узкую колонку по центру');
        $this->assertPixelCloseTo($canvas, 1054, 779, [0, 0, 255], 'одинокая вещь в ряду должна занимать всю ширину, не узкую колонку по центру');
        // Нижняя строка — 2 «компактные» вещи (кроссовки/сумка).
        $this->assertPixelCloseTo($canvas, 278, 1168, [255, 255, 0]);
        $this->assertPixelCloseTo($canvas, 802, 1168, [255, 0, 255]);
        imagedestroy($canvas);
    }

    public function testItemWithoutPhotoRendersGrayPlaceholderInsteadOfCrashing(): void
    {
        $noPhoto = $this->item(1, 'Платье', null, name: 'Платье без фото', color: 'изумрудный');

        $path = $this->renderer()->render($this->outfit(103), [$noPhoto, $this->item(2, 'Кроссовки', $this->solidImage(900, 700, 0, 200, 0))]);

        self::assertNotNull($path, 'вещь без фото не должна ронять рендер');
        $canvas = imagecreatefromjpeg($path);
        // gray-100 tailwind (243,244,246) — та же плашка, что и в _outfit_card.html.twig.
        // Точка у верхнего края «высокой» ячейки (y=136), заведомо выше центрированного текста.
        $this->assertPixelCloseTo($canvas, 270, 136, [243, 244, 246]);
        // Название и «категория · цвет» реально нарисованы, а не потерялись (полная строка
        // «Платье · изумрудный» на узкой ячейке раньше обрезалась до «Платье ·», см.
        // drawPlaceholder/wrap) — сканируем ТОЛЬКО «высокую» ячейку (24,116)-(1056,995);
        // строка компактных вещей ниже (y>=1011) — не она.
        self::assertTrue(
            $this->tileHasNonBackgroundPixels($canvas, 24, 116, 1032, 879),
            'плейсхолдер должен содержать видимый текст (название/категория/цвет), а не быть пустой плашкой',
        );
        imagedestroy($canvas);
    }

    public function testOversizedSourcePhotoFallsBackToPlaceholderInsteadOfExhaustingMemory(): void
    {
        // 4000x3200 = 12.8M пикселей > MAX_SOURCE_PIXELS (12M) — должен деградировать, не упасть.
        $huge = $this->solidImage(4000, 3200, 0, 0, 255, quality: 15);
        $item = $this->item(1, 'Платье', $huge);

        $path = $this->renderer()->render($this->outfit(104), [$item, $this->item(2, 'Кроссовки', null)]);

        self::assertNotNull($path);
        $canvas = imagecreatefromjpeg($path);
        $this->assertPixelCloseTo($canvas, 270, 136, [243, 244, 246]);
        imagedestroy($canvas);
    }

    /** Заголовок повода (WardrobeOutfit::DAILY_OCCASIONS) — единственная подпись на холсте (требование ревью). */
    public function testHeaderRendersOccasionLabel(): void
    {
        $outfit = $this->outfit(108);
        $outfit->setOccasion(WardrobeOutfit::OCCASION_THEATER);

        $path = $this->renderer()->render($outfit, [$this->item(1, 'Платье', $this->solidImage(900, 1200, 0, 0, 0))]);

        self::assertNotNull($path);
        $canvas = imagecreatefromjpeg($path);
        self::assertTrue(
            $this->tileHasNonBackgroundPixels($canvas, 24, 10, 1032, 80),
            'в полосе заголовка должен быть виден текст повода',
        );
        imagedestroy($canvas);
    }

    public function testRerenderIsIdempotentAndDoesNotOverwriteExistingFile(): void
    {
        $renderer = $this->renderer();
        $outfit = $this->outfit(105);
        $items = [$this->item(1, 'Платье', $this->solidImage(900, 1200, 10, 10, 10)), $this->item(2, 'Кроссовки', $this->solidImage(900, 700, 20, 20, 20))];

        $first = $renderer->render($outfit, $items);
        self::assertNotNull($first);
        $originalBytes = file_get_contents($first);

        // Другие вещи/цвета — но файл для того же id уже есть, повторный вызов его не трогает
        // (иначе «Собрать образы» никогда не станет тяжелее одного прохода на образ).
        $again = $renderer->render($outfit, [$this->item(3, 'Платье', $this->solidImage(900, 1200, 250, 250, 250))]);

        self::assertSame($first, $again);
        self::assertSame($originalBytes, file_get_contents($again));
    }

    public function testExistsReflectsWhetherFileWasRendered(): void
    {
        $renderer = $this->renderer();
        $outfit = $this->outfit(106);

        self::assertFalse($renderer->exists(106));
        $renderer->render($outfit, [$this->item(1, 'Платье', $this->solidImage(900, 1200, 5, 5, 5)), $this->item(2, 'Кроссовки', null)]);
        self::assertTrue($renderer->exists(106));
    }

    private function renderer(): WardrobeOutfitCollageRenderer
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('resolvePath')->willReturnCallback(
            fn (object $obj): ?string => $this->photoPaths[spl_object_id($obj)] ?? null,
        );

        return new WardrobeOutfitCollageRenderer(
            $this->projectDir,
            __DIR__ . '/../../../config/social/fonts/NotoSans.ttf',
            $storage,
        );
    }

    private function outfit(int $id): WardrobeOutfit
    {
        $outfit = new WardrobeOutfit();
        $ref = new \ReflectionProperty(WardrobeOutfit::class, 'id');
        $ref->setValue($outfit, $id);

        return $outfit;
    }

    private function item(int $id, string $category, ?string $photoPath, ?string $name = null, string $color = 'чёрный'): WardrobeItem
    {
        $item = (new WardrobeItem())->setCategory($category)->setName($name ?? ('Вещь ' . $id))->setColorName($color);
        $ref = new \ReflectionProperty(WardrobeItem::class, 'id');
        $ref->setValue($item, $id);
        $this->photoPaths[spl_object_id($item)] = $photoPath;

        return $item;
    }

    /** Есть ли в прямоугольнике тайла хоть один пиксель заметно темнее фона-плашки (243,244,246) — признак нарисованного текста. */
    private function tileHasNonBackgroundPixels(\GdImage $canvas, int $x, int $y, int $w, int $h): bool
    {
        for ($py = $y; $py < $y + $h; $py += 4) {
            for ($px = $x; $px < $x + $w; $px += 4) {
                $rgb = imagecolorat($canvas, $px, $py);
                $r = ($rgb >> 16) & 0xFF;
                if ($r < 200) {
                    return true;
                }
            }
        }

        return false;
    }

    private function solidImage(int $w, int $h, int $r, int $g, int $b, int $quality = 90): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wb_outfit_collage_src_') . '.jpg';
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, $r, $g, $b));
        imagejpeg($im, $path, $quality);
        imagedestroy($im);

        return $path;
    }

    /** @param array{0:int,1:int,2:int} $expectedRgb допуск на JPEG-артефакты сжатия */
    private function assertPixelCloseTo(\GdImage $canvas, int $x, int $y, array $expectedRgb, string $message = ''): void
    {
        $rgb = imagecolorat($canvas, $x, $y);
        $actual = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
        foreach ($expectedRgb as $i => $channel) {
            self::assertLessThanOrEqual(30, abs($actual[$i] - $channel), sprintf(
                '%sпиксель (%d,%d): ожидали ~rgb(%d,%d,%d), получили rgb(%d,%d,%d)',
                $message !== '' ? $message . ' — ' : '',
                $x, $y, $expectedRgb[0], $expectedRgb[1], $expectedRgb[2], $actual[0], $actual[1], $actual[2],
            ));
        }
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
