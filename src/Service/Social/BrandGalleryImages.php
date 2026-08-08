<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Brand;
use App\Entity\BrandImage;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Слайды карусели из реальных фотографий бренда (brand_image), а не из AI-карточек.
 *
 * Берём поле image (JPEG-оригинал), не preview (webp): Instagram принимает JPEG, а
 * PublicMediaHost на уже-JPEG не тратит конвертацию. Файлы brand_image лежат ПЛОСКО в
 * public_html/images/brands/<image> (как лого) — SubdirDirectoryNamer из vich-конфига
 * относится только к новым загрузкам, весь импортный корпус плоский, публичные шаблоны
 * рендерят его как asset('images/brands/' ~ image).
 *
 * Отсутствующий на диске файл молча выкидываем: строка в БД без файла даст 404 у Meta
 * и завалит публикацию всего поста.
 */
class BrandGalleryImages
{
    /** Публичный префикс = uri_prefix маппинга brand_image_image. */
    private const PUBLIC_PREFIX = '/images/brands/';

    /** Лимит слайдов в карусели Instagram. */
    public const MAX_SLIDES = 10;

    /** Меньше двух слайдов — это не карусель, такой пост смысла не имеет. */
    public const MIN_SLIDES = 2;

    /** Ранги групп frame_kind для сортировки (меньше — раньше). NULL = ещё не классифицирован. */
    private const GROUP_PRODUCT_PERSON = 0;
    private const GROUP_PRODUCT_FLAT = 1;
    private const GROUP_UNCLASSIFIED = 2;
    private const GROUP_SCENE = 3;
    private const GROUP_OTHER = 4;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Публичные пути слайдов по порядку сортировки бренда, только реально существующие файлы.
     *
     * Порядок определяет frame_kind (app:social:classify-frames): product_person → product_flat →
     * неклассифицированные (NULL) → scene → other. other — контентное дно (витрина/текст/логотип),
     * его берём в самый конец и ТОЛЬКО чтобы дотянуть до MIN_SLIDES — иначе бедные галереи вообще
     * не набирались бы на карусель. Внутри product_person вертикальный кадр (h>w) идёт первым:
     * это открывающий кадр карусели/обложка Reels, он задаёт кадрирование — то самое первое, что
     * видит зритель в решающие 1.5с (живой инцидент: горизонтальный тёмный лукбук первым слайдом
     * убивал удержание).
     *
     * @return list<string> напр. ['/images/brands/1763218812742_67249757c90510da.jpg', ...]
     */
    public function paths(Brand $brand, int $max = self::MAX_SLIDES): array
    {
        $core = [];
        $other = [];

        foreach ($brand->getImages() as $image) {
            if (!$image instanceof BrandImage || $image->getStatus() !== Statuses::Active) {
                continue;
            }

            $file = $image->getImage();
            if ($file === null || trim($file) === '') {
                continue;
            }

            $absolutePath = $this->projectDir . '/public_html' . self::PUBLIC_PREFIX . $file;
            if (!is_file($absolutePath)) {
                continue;
            }

            $row = [
                'id' => $image->getId(),
                'path' => self::PUBLIC_PREFIX . $file,
                'groupRank' => $this->groupRank($image->getFrameKind()),
                'vertical' => $this->isVertical($absolutePath),
            ];

            $row['groupRank'] === self::GROUP_OTHER ? $other[] = $row : $core[] = $row;
        }

        usort($core, $this->compareRows(...));
        usort($other, $this->compareRows(...));

        // other — только если без него не набирается MIN_SLIDES, и всегда в самом конце.
        $ordered = count($core) >= self::MIN_SLIDES ? $core : [...$core, ...$other];

        return array_slice(array_column($ordered, 'path'), 0, $max);
    }

    private function groupRank(?string $frameKind): int
    {
        return match ($frameKind) {
            BrandImage::FRAME_PRODUCT_PERSON => self::GROUP_PRODUCT_PERSON,
            BrandImage::FRAME_PRODUCT_FLAT => self::GROUP_PRODUCT_FLAT,
            BrandImage::FRAME_SCENE => self::GROUP_SCENE,
            BrandImage::FRAME_OTHER => self::GROUP_OTHER,
            default => self::GROUP_UNCLASSIFIED, // NULL — ещё не классифицирован
        };
    }

    private function isVertical(string $absolutePath): bool
    {
        $size = @getimagesize($absolutePath);

        return $size !== false && $size[1] > $size[0];
    }

    /** @param array{id:?int,path:string,groupRank:int,vertical:bool} $a */
    private function compareRows(array $a, array $b): int
    {
        if ($a['groupRank'] !== $b['groupRank']) {
            return $a['groupRank'] <=> $b['groupRank'];
        }

        // Вертикаль первой — только в открывающей группе (product_person): она даёт обложку.
        if ($a['groupRank'] === self::GROUP_PRODUCT_PERSON && $a['vertical'] !== $b['vertical']) {
            return $a['vertical'] ? -1 : 1;
        }

        return $a['id'] <=> $b['id'];
    }
}
