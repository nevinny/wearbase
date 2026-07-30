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

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Публичные пути слайдов по порядку сортировки бренда, только реально существующие файлы.
     *
     * @return list<string> напр. ['/images/brands/1763218812742_67249757c90510da.jpg', ...]
     */
    public function paths(Brand $brand, int $max = self::MAX_SLIDES): array
    {
        $paths = [];

        foreach ($brand->getImages() as $image) {
            if (!$image instanceof BrandImage || $image->getStatus() !== Statuses::Active) {
                continue;
            }

            $file = $image->getImage();
            if ($file === null || trim($file) === '') {
                continue;
            }

            $publicPath = self::PUBLIC_PREFIX . $file;
            if (!is_file($this->projectDir . '/public_html' . $publicPath)) {
                continue;
            }

            $paths[] = $publicPath;
            if (count($paths) >= $max) {
                break;
            }
        }

        return $paths;
    }
}
