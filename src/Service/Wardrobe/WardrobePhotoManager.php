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

        $this->backfillLegacyCoverRow($item);

        $nextSort = count($item->getActivePhotos());
        $hasCover = $item->getCoverPhoto() !== null;
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
            throw new \InvalidArgumentException('Фотография не принадлежит этой вещи или уже удалена.');
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
            throw new \InvalidArgumentException('Фотография не принадлежит этой вещи или уже удалена.');
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

    /**
     * Легаси-вещи (созданы обычной формой /new или /edit до появления галереи) хранят
     * фото только в item.photo, без строки в галерее — «На обложку» для него в принципе
     * недоступна (нет строки — нет кнопки), а getCoverPhoto() фолбэком на [0] отдаёт
     * первое загруженное в галерею фото любого типа. Перед первой загрузкой в галерею
     * заводим для legacy-фото ровно такую же строку, какую создаёт бэкафилл-миграция
     * Version20260726_wardrobe_gallery_archive, — тогда оно становится обычной строкой
     * галереи с isCover=true, и обложку можно вернуть кнопкой.
     */
    private function backfillLegacyCoverRow(WardrobeItem $item): void
    {
        if ($item->getActivePhotos() !== [] || $item->getPhoto() === null) {
            return;
        }

        $legacy = (new WardrobeItemPhoto())
            ->setFilePath($item->getPhoto())
            ->setPhotoType(WardrobeItemPhoto::TYPE_COVER)
            ->setSortOrder(0)
            ->setSource($this->legacySource($item))
            ->setIsCover(true);
        $item->addPhoto($legacy);
        $this->entityManager->persist($legacy);
    }

    /**
     * Замена основного фото через форму «Редактировать» (VichImageType на photoFile,
     * в обход галереи): вызвать сразу после $form->handleRequest()+flush(), когда Vich
     * уже сохранил новый файл и item.photo указывает на него. Старый файл физически не
     * трогаем (см. vich_uploader.yaml delete_on_update: false) — он остаётся строкой
     * галереи (заводим её, если её не было), просто перестаёт быть обложкой; новый файл
     * становится обложкой — это и есть смысл действия пользователя «заменить фото».
     */
    public function reconcileAfterLegacyReplace(WardrobeItem $item, ?string $previousPhoto): void
    {
        $newPhoto = $item->getPhoto();
        if ($newPhoto === null || $newPhoto === $previousPhoto) {
            return;
        }

        $previousRow = null;
        foreach ($item->getActivePhotos() as $photo) {
            if ($photo->getFilePath() === $previousPhoto) {
                $previousRow = $photo;
                break;
            }
        }

        if ($previousRow === null && $previousPhoto !== null) {
            $previousRow = (new WardrobeItemPhoto())
                ->setFilePath($previousPhoto)
                ->setPhotoType(WardrobeItemPhoto::TYPE_COVER)
                ->setSortOrder(count($item->getActivePhotos()))
                ->setSource($this->legacySource($item));
            $item->addPhoto($previousRow);
            $this->entityManager->persist($previousRow);
        }

        foreach ($item->getActivePhotos() as $photo) {
            $photo->setIsCover(false);
        }

        $newRow = (new WardrobeItemPhoto())
            ->setFilePath($newPhoto)
            ->setPhotoType(WardrobeItemPhoto::TYPE_COVER)
            ->setSortOrder(count($item->getActivePhotos()))
            ->setSource(WardrobeItemPhoto::SOURCE_UPLOAD)
            ->setIsCover(true);
        $item->addPhoto($newRow);
        $this->entityManager->persist($newRow);
        $this->entityManager->flush();
    }

    /**
     * Тот же эвристический признак происхождения, что и в бэкафилл-миграции
     * Version20260726_wardrobe_gallery_archive: своя загрузка / WB-автозагрузка / импорт
     * различить нечем, кроме source вещи и наличия product_url.
     */
    private function legacySource(WardrobeItem $item): string
    {
        if ($item->getSource() === WardrobeItem::SOURCE_IMPORT) {
            return WardrobeItemPhoto::SOURCE_IMPORT;
        }
        if ($item->getSource() === WardrobeItem::SOURCE_WEB && ($item->getProductUrl() ?? '') !== '') {
            return WardrobeItemPhoto::SOURCE_MARKETPLACE;
        }

        return WardrobeItemPhoto::SOURCE_UPLOAD;
    }
}
