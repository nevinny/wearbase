<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemPhoto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class WardrobePhotoManager
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_FILE_SIZE = 10_000_000;
    private const MAX_BATCH_SIZE = 8;

    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /** @param UploadedFile[] $files
     *  @return WardrobeItemPhoto[]
     */
    public function upload(WardrobeItem $item, array $files, string $photoType): array
    {
        if ($files === []) {
            throw new \InvalidArgumentException('Выберите хотя бы одну фотографию.');
        }
        if (count($files) > self::MAX_BATCH_SIZE) {
            throw new \InvalidArgumentException('За один раз можно загрузить не больше 8 фотографий.');
        }

        $allowedTypes = [
            WardrobeItemPhoto::TYPE_PRODUCT, WardrobeItemPhoto::TYPE_BACK,
            WardrobeItemPhoto::TYPE_DETAIL, WardrobeItemPhoto::TYPE_LABEL,
            WardrobeItemPhoto::TYPE_CARE, WardrobeItemPhoto::TYPE_RECEIPT,
        ];
        if (!in_array($photoType, $allowedTypes, true)) {
            $photoType = WardrobeItemPhoto::TYPE_PRODUCT;
        }

        $nextSort = count($item->getActivePhotos());
        $hasCover = $item->getCoverPhoto() !== null || $item->getPhoto() !== null;
        $created = [];
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw new \InvalidArgumentException('Один из файлов не удалось загрузить.');
            }
            if (($file->getSize() ?: 0) > self::MAX_FILE_SIZE || !in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
                throw new \InvalidArgumentException('Разрешены JPG, PNG и WebP размером до 10 МБ.');
            }

            $photo = (new WardrobeItemPhoto())
                ->setFile($file)
                ->setPhotoType($photoType)
                ->setSortOrder($nextSort++)
                ->setOriginalFilename($file->getClientOriginalName())
                ->setMimeType($file->getMimeType())
                ->setFileSize($file->getSize() ?: null)
                ->setIsCover(!$hasCover);
            $hasCover = true;
            $item->addPhoto($photo);
            $this->entityManager->persist($photo);
            $created[] = $photo;
        }

        $this->entityManager->flush();
        if ($item->getPhoto() === null && ($cover = $item->getCoverPhoto()) !== null) {
            $item->setPhoto($cover->getFilePath());
            $this->entityManager->flush();
        }

        return $created;
    }

    public function setCover(WardrobeItem $item, WardrobeItemPhoto $selected): void
    {
        if ($selected->getItem() !== $item || $selected->isDeleted()) {
            throw new \InvalidArgumentException('Фотография не принадлежит этой вещи.');
        }
        foreach ($item->getActivePhotos() as $photo) {
            $photo->setIsCover($photo === $selected);
        }
        $item->setPhoto($selected->getFilePath());
        $this->entityManager->flush();
    }

    public function softDelete(WardrobeItem $item, WardrobeItemPhoto $selected): void
    {
        if ($selected->getItem() !== $item || $selected->isDeleted()) {
            throw new \InvalidArgumentException('Фотография не принадлежит этой вещи.');
        }
        $wasCover = $selected->isCover();
        $selected->setIsCover(false);
        $selected->softDelete();

        if ($wasCover) {
            $next = $item->getActivePhotos()[0] ?? null;
            $next?->setIsCover(true);
            $item->setPhoto($next?->getFilePath());
        }
        $this->entityManager->flush();
    }
}
