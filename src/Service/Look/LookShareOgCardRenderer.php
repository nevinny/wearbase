<?php

declare(strict_types=1);

namespace App\Service\Look;

use App\Entity\User;
use App\Entity\WardrobeItemPhoto;
use App\Entity\WardrobeOutfitShare;
use Doctrine\ORM\EntityManagerInterface;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Pre-rendered OG-карта лука 1200x630 для Telegram/MAX/VK/WA (спец §3.2).
 *
 * Приватность несовершеннолетних (§4.3): у детских луков в карту идут только
 * flat-фото вещей (обложка/каталожный кадр); если их нет — брендированный
 * плейсхолдер без фотографий. Имена владельцев/детей никогда не попадают в текст.
 * Файл кладётся в приватное хранилище var/uploads/look_share_og (НЕ web-root,
 * урок миграции legacy /images/wardrobe*) и раздаётся через look_shared_og.
 */
final class LookShareOgCardRenderer
{
    private const WIDTH = 1200;
    private const HEIGHT = 630;
    private const PADDING = 64;
    private const TITLE_SIZE = 52;
    private const TITLE_LINE_HEIGHT = 64;
    private const THUMB = 176;
    /** Flat-фото (§4.3): обложка и каталожный кадр вещи. */
    private const FLAT_PHOTO_TYPES = [WardrobeItemPhoto::TYPE_COVER, WardrobeItemPhoto::TYPE_PRODUCT];
    private const MAX_TITLE_LINES = 3;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $fontPath,
        private readonly EntityManagerInterface $em,
        private readonly StorageInterface $storage,
    ) {}

    /** @return string|null абсолютный путь к PNG или null (нет шрифта/GD-сбой). */
    public function renderFor(WardrobeOutfitShare $share): ?string
    {
        if (!is_file($this->fontPath) || !function_exists('imagecreatetruecolor')) {
            return null;
        }

        $dir = $this->projectDir.'/var/uploads/look_share_og';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $path = $dir.'/'.$share->getToken().'.png';
        if (is_file($path)) {
            return $path; // карта генерится один раз на ссылку; изменение лука не подтягивается (§3.3)
        }

        $im = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $bg = imagecolorallocate($im, 17, 24, 39); // tailwind gray-900
        $fg = imagecolorallocate($im, 255, 255, 255);
        $muted = imagecolorallocate($im, 156, 163, 175);
        $cardBg = imagecolorallocate($im, 31, 41, 55);
        imagefilledrectangle($im, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $bg);

        $photos = $this->photosFor($share);
        $titleLines = $this->wrap($share->getOutfit()->getTitle(), self::WIDTH - 2 * self::PADDING);
        $y = self::PADDING + self::TITLE_SIZE;
        foreach ($titleLines as $line) {
            imagettftext($im, self::TITLE_SIZE, 0, self::PADDING, $y, $fg, $this->fontPath, $line);
            $y += self::TITLE_LINE_HEIGHT;
        }

        if ($photos === []) {
            // Плейсхолдер без фото: брендированная карточка с текстом по центру остатка холста.
            $note = 'Лук собран в WEARBASE';
            $nbbox = imagettfbbox(28, 0, $this->fontPath, $note);
            imagettftext(
                $im,
                28,
                0,
                (int) ((self::WIDTH - ($nbbox[2] - $nbbox[0])) / 2),
                (int) (($y + self::HEIGHT) / 2),
                $muted,
                $this->fontPath,
                $note,
            );
        } else {
            $thumbY = min($y + self::PADDING / 2, self::HEIGHT - self::THUMB - self::PADDING);
            $x = self::PADDING;
            foreach ($photos as $photo) {
                imagefilledrectangle($im, $x - 2, (int) $thumbY - 2, $x + self::THUMB + 2, (int) $thumbY + self::THUMB + 2, $cardBg);
                $srcPath = $this->storage->resolvePath($photo, 'file');
                $src = is_string($srcPath) ? $this->loadImage($srcPath) : null;
                if ($src !== null) {
                    $this->copyCropped($im, $src, $x, (int) $thumbY, self::THUMB, self::THUMB);
                    imagedestroy($src);
                }
                $x += self::THUMB + 16;
            }
        }

        $footer = 'WEARBASE · российские бренды в одном месте';
        $fbbox = imagettfbbox(24, 0, $this->fontPath, $footer);
        imagettftext(
            $im,
            24,
            0,
            (int) ((self::WIDTH - ($fbbox[2] - $fbbox[0])) / 2),
            self::HEIGHT - 36,
            $muted,
            $this->fontPath,
            $footer,
        );

        return imagepng($im, $path) ? $path : null;
    }

    /**
     * Фото обложек вещей снапшота. Для детских луков (familyRole = child) — только
     * flat-фото; если подходящих нет, возвращается пустой список → плейсхолдер.
     *
     * @return list<WardrobeItemPhoto>
     */
    private function photosFor(WardrobeOutfitShare $share): array
    {
        $outfit = $share->getOutfit();
        $ids = array_map(static fn (array $entry): int => (int) $entry['id'], $outfit->getItems());
        if ($ids === []) {
            return [];
        }

        $qb = $this->em->getRepository(WardrobeItemPhoto::class)->createQueryBuilder('p')
            ->innerJoin('p.item', 'i')
            ->where('i.id IN (:ids)')
            ->andWhere('i.user = :owner')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('ids', array_values(array_unique($ids)))
            ->setParameter('owner', $outfit->getWardrobeOwner())
            ->orderBy('p.isCover', 'DESC')
            ->addOrderBy('p.sortOrder', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setMaxResults(4);

        if (($outfit->getWardrobeOwner()?->getFamilyRole() ?? null) === User::FAMILY_ROLE_CHILD) {
            $qb->andWhere('p.photoType IN (:flat)')
                ->setParameter('flat', self::FLAT_PHOTO_TYPES);
        }

        return $qb->getQuery()->getResult();
    }

    private function loadImage(string $path): ?\GdImage
    {
        if (!is_file($path)) {
            return null;
        }
        try {
            $image = @imagecreatefromstring((string) file_get_contents($path));

            return $image instanceof \GdImage ? $image : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Cover-кроп источника в целевой прямоугольник (как object-fit: cover). */
    private function copyCropped(\GdImage $dst, \GdImage $src, int $dx, int $dy, int $dw, int $dh): void
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) {
            return;
        }
        $scale = max($dw / $sw, $dh / $sh);
        $cw = (int) max(1, round($dw / $scale));
        $ch = (int) max(1, round($dh / $scale));
        $cx = (int) max(0, floor(($sw - $cw) / 2));
        $cy = (int) max(0, floor(($sh - $ch) / 2));

        imagecopyresampled($dst, $src, $dx, $dy, $cx, $cy, $dw, $dh, $cw, $ch);
    }

    /** @return string[] */
    private function wrap(?string $text, int $maxWidth): array
    {
        $words = preg_split('/\s+/u', trim((string) $text)) ?: [];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $test = $current === '' ? $word : $current.' '.$word;
            $bbox = imagettfbbox(self::TITLE_SIZE, 0, $this->fontPath, $test);
            if (($bbox[2] - $bbox[0]) > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = $word;
                if (count($lines) === self::MAX_TITLE_LINES) {
                    break;
                }
            } else {
                $current = $test;
            }
        }
        if ($current !== '' && count($lines) < self::MAX_TITLE_LINES) {
            $lines[] = $current;
        }

        return $lines ?: ['Образ дня'];
    }
}
