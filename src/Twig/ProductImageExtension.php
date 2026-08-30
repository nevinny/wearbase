<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\ProductImage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * URL картинки товара. В БД у ProductImage лежит голое имя файла, но физически
 * файлы живут в ДВУХ раскладках:
 *  - импорт (ImageDownloaderService) — плоско: public_html/images/products/<файл>;
 *  - загрузка из ЛК (Vich, SubdirDirectoryNamer 2×2) — public_html/images/products/aa/bb/<файл>.
 * Плоский asset('images/products/' ~ имя) для Vich-файлов даёт 404 (битые картинки
 * первых саморег-товаров в каталоге), безусловный vich_uploader_asset сломал бы
 * импортные. Поэтому: есть плоский файл — отдаём его, иначе резолвим через Vich.
 */
class ProductImageExtension extends AbstractExtension
{
    private const URI_PREFIX = '/images/products';

    public function __construct(
        private readonly StorageInterface $vichStorage,
        #[Autowire('%kernel.project_dir%/public_html')]
        private readonly string $publicDir,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('product_image_url', $this->productImageUrl(...)),
        ];
    }

    /**
     * @param string $prefer 'preview' — сначала превью (карточки-миниатюры),
     *                       'image' — сначала оригинал (галерея, data-full, JSON-LD)
     */
    public function productImageUrl(?ProductImage $image, string $prefer = 'preview'): ?string
    {
        if ($image === null) {
            return null;
        }

        $fields = $prefer === 'image'
            ? ['image' => $image->getImage(), 'preview' => $image->getPreview()]
            : ['preview' => $image->getPreview(), 'image' => $image->getImage()];

        foreach ($fields as $field => $name) {
            if ($name === null || $name === '') {
                continue;
            }
            if (is_file($this->publicDir . self::URI_PREFIX . '/' . $name)) {
                return self::URI_PREFIX . '/' . $name;
            }

            return $this->vichStorage->resolveUri($image, $field . 'File');
        }

        return null;
    }
}
