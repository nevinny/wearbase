<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\BrandStyle;
use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeTransfer;
use App\Repository\WardrobeItemRepository;
use App\Repository\WardrobeTransferRepository;
use Vich\UploaderBundle\Storage\StorageInterface;

class WardrobeExportService
{
    public function __construct(
        private readonly WardrobeItemRepository $itemRepository,
        private readonly WardrobeTransferRepository $transferRepository,
        private readonly StorageInterface $storage,
    ) {}

    /**
     * @param User[] $owners
     * @return array<string, mixed>
     */
    public function export(array $owners, bool $withArchive): array
    {
        $items = $this->itemRepository->findForExport($owners, $withArchive);
        $transfers = $this->transferRepository->findGroupedForItems($items);

        return [
            'format' => 'wearbase.wardrobe',
            'version' => 1,
            'exported_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'includes_archive' => $withArchive,
            'owners' => array_map($this->ownerData(...), $owners),
            'items' => array_map(
                fn (WardrobeItem $item): array => $this->itemData($item, $transfers[$item->getId()] ?? []),
                $items,
            ),
        ];
    }

    /** @param array<string, mixed> $export */
    public function toCsv(array $export): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Не удалось подготовить CSV.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [
            'ID владельца', 'Владелец', '№ вещи', 'Название', 'Категория', 'Родительская категория',
            'Бренд', 'Цвет', 'Размер', 'Материал', 'Страна', 'Сезон', 'Стили', 'Стоимость',
            'Дата покупки', 'Статус карточки', 'Статус вещи', 'Статус носки', 'Источник',
            'Ссылка на товар', 'Фото', 'История передач', 'Заметки',
        ], ';');

        foreach ($export['items'] as $item) {
            fputcsv($stream, [
                $item['owner']['source_user_id'],
                $item['owner']['name'],
                $item['item_no'],
                $item['name'],
                $item['category']['name'],
                $item['category']['parent'],
                $item['brand'],
                $item['color'],
                $item['size'],
                $item['material'],
                $item['country_of_origin'],
                $item['season'],
                implode(', ', $item['styles']),
                $item['price'],
                $item['purchased_at'],
                $item['completion_status'],
                $item['item_status'],
                $item['wear_status'],
                $item['source'],
                $item['product_url'],
                implode(', ', array_column($item['photos'], 'url')),
                json_encode($item['transfers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $item['notes'],
            ], ';');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv === false ? '' : $csv;
    }

    /** @return array{source_user_id: int|null, name: string} */
    private function ownerData(User $owner): array
    {
        return [
            'source_user_id' => $owner->getId(),
            'name' => $owner->getFullName(),
        ];
    }

    /**
     * @param WardrobeTransfer[] $transfers
     * @return array<string, mixed>
     */
    private function itemData(WardrobeItem $item, array $transfers): array
    {
        $category = $item->getCategoryRef();
        $photos = [];
        if ($item->getPhoto() !== null) {
            $photos[] = [
                'url' => $this->storage->resolveUri($item, 'photoFile'),
                'type' => 'legacy',
                'cover' => true,
            ];
        }
        foreach ($item->getActivePhotos() as $photo) {
            if ($photo->getFilePath() !== null) {
                $photos[] = [
                    'url' => $this->storage->resolveUri($photo, 'file'),
                    'type' => $photo->getPhotoType(),
                    'cover' => $photo->isCover(),
                ];
            }
        }

        return [
            'source_item_id' => $item->getId(),
            'owner' => $this->ownerData($item->getUser()),
            'original_owner' => $item->getOriginalOwner() ? $this->ownerData($item->getOriginalOwner()) : null,
            'item_no' => $item->getItemNo(),
            'name' => $item->getName(),
            'category' => [
                'code' => $category?->getCode(),
                'name' => $item->getCategory(),
                'parent' => $category?->getParent()?->getName(),
            ],
            'brand' => $item->getCustomBrandName(),
            'color' => $item->getColorName(),
            'size' => $item->getSize(),
            'material' => $item->getMaterialText(),
            'country_of_origin' => $item->getCountryOfOrigin(),
            'season' => $item->getSeason(),
            'styles' => $item->getStyles()
                ->map(static fn (BrandStyle $style): ?string => $style->getSlug())
                ->filter(static fn (?string $slug): bool => $slug !== null)
                ->getValues(),
            'price' => $item->getPrice(),
            'purchased_at' => $item->getPurchasedAt()?->format('Y-m-d'),
            'purchase_reason' => $item->getPurchaseReason(),
            'love_at_first_sight' => $item->getLoveAtFirstSight(),
            'care' => $item->getCareText(),
            'pros' => $item->getPros(),
            'cons' => $item->getCons(),
            'verdict' => $item->getVerdict(),
            'notes' => $item->getNotes(),
            'product_url' => $item->getProductUrl(),
            'completion_status' => $item->getCompletionStatus(),
            'item_status' => $item->getItemStatus(),
            'wear_status' => $item->getWearStatus(),
            'source' => $item->getSource(),
            'photos' => $photos,
            'transfers' => array_map($this->transferData(...), $transfers),
            'created_at' => $item->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $item->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function transferData(WardrobeTransfer $transfer): array
    {
        return [
            'source_transfer_id' => $transfer->getId(),
            'from' => $this->ownerData($transfer->getFromUser()),
            'to' => $this->ownerData($transfer->getToUser()),
            'actor' => $this->ownerData($transfer->getActor()),
            'transferred_at' => $transfer->getTransferredAt()->format(\DateTimeInterface::ATOM),
            'note' => $transfer->getNote(),
        ];
    }

}
