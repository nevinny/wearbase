<?php

namespace App\Service;

use App\Entity\Alphabet;
use App\Entity\Brand;
use App\Repository\AlphabetRepository;
use Doctrine\ORM\EntityManagerInterface;

class AlphabetManagerService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AlphabetRepository $alphabetRepository
    ) {}

    public function updateAlphabetFromBrand(Brand $brand, string $locale, bool $isNew = false): void
    {
        if (!$brand->isPublished()) {
            return;
        }

        $firstLetter = $brand->getFirstLetter();

        if (!$firstLetter) {
            return;
        }

        $alphabet = $this->alphabetRepository->findOneByLetterAndLocale($firstLetter, $locale);

        if (!$alphabet) {
            $alphabet = new Alphabet();
            $alphabet->setLetter($firstLetter);
            $alphabet->setLocale($locale);
            $alphabet->setBrandsCount(1);
        } elseif ($isNew) {
            $alphabet->incrementBrandsCount();
        }

        $this->entityManager->persist($alphabet);
    }

    public function handleBrandCreation(Brand $brand, string $locale): void
    {
        $this->updateAlphabetFromBrand($brand, $locale, true);
        $this->entityManager->flush();
    }

    public function handleBrandUpdate(Brand $brand, ?string $oldFirstLetter = null, ?bool $oldStatus = null): void
    {
        $currentFirstLetter = $brand->getFirstLetter();
        $currentStatus = $brand->isPublished();

        // Получаем локали ТОЛЬКО если буквы существуют
        $currentLocale = $currentFirstLetter ? $this->detectLocaleByLetter($currentFirstLetter) : null;
        $oldLocale = $oldFirstLetter ? $this->detectLocaleByLetter($oldFirstLetter) : null;

        $letterChanged = $oldFirstLetter !== $currentFirstLetter;
        $statusChanged = $oldStatus !== null && $oldStatus !== $currentStatus;

        // Случай 1: Буква появилась впервые (было null)
        if ($oldFirstLetter === null && $currentFirstLetter !== null && $currentLocale) {
            if ($currentStatus === true) {
                $this->updateAlphabetFromBrand($brand, $currentLocale, true);
            }
        }

        // Случай 2: Буква была удалена (стала null)
        elseif ($oldFirstLetter !== null && $currentFirstLetter === null && $oldLocale) {
            // ✅ ИСПРАВЛЕНО: декремент ТОЛЬКО если был активен
            if ($oldStatus === true) {
                $this->decrementBrandCount($oldFirstLetter, $oldLocale);
            }
        }

        // Случай 3: Изменились И буква И статус
        elseif ($letterChanged && $statusChanged && $oldFirstLetter !== null && $currentFirstLetter !== null && $oldLocale && $currentLocale) {
            // Убираем из старой буквы (если был активен)
            if ($oldStatus === true) {
                $this->decrementBrandCount($oldFirstLetter, $oldLocale);
            }

            // Добавляем в новую букву (если стал активен)
            if ($currentStatus === true) {
                $this->updateAlphabetFromBrand($brand, $currentLocale, true);
            }
        }

        // Случай 4: Изменилась ТОЛЬКО буква (статус не менялся или неизвестен)
        elseif ($letterChanged && $oldFirstLetter !== null && $currentFirstLetter !== null && $oldLocale && $currentLocale) {
            // ✅ ИСПРАВЛЕНО: Обрабатываем ВСЕ подслучаи

            // Подслучай 4.1: Статус известен и не менялся
            if (!$statusChanged) {
                if ($currentStatus === true) {
                    // Активный бренд: переносим счетчик
                    $this->decrementBrandCount($oldFirstLetter, $oldLocale);
                    $this->updateAlphabetFromBrand($brand, $currentLocale, true);
                }
                // Если неактивен - ничего не делаем (счетчиков и не было)
            }
            // Подслучай 4.2: Статус неизвестен (oldStatus === null), используем текущий
            else {
                // Если бренд сейчас активен - предполагаем, что был активен
                if ($currentStatus === true) {
                    $this->decrementBrandCount($oldFirstLetter, $oldLocale);
                    $this->updateAlphabetFromBrand($brand, $currentLocale, true);
                }
            }
        }

        // Случай 5: Изменился ТОЛЬКО статус (буква не менялась)
        elseif (!$letterChanged && $statusChanged && $currentFirstLetter !== null && $currentLocale) {
            if ($currentStatus === true) {
                // Стал активным
                $this->updateAlphabetFromBrand($brand, $currentLocale, true);
            } else {
                // Стал неактивным
                $this->decrementBrandCount($currentFirstLetter, $currentLocale);
            }
        }

        // Случай 6: Ничего критичного не изменилось
        else {
            if ($currentStatus === true && $currentFirstLetter !== null && $currentLocale) {
                // Обновляем данные без изменения счетчика
                $this->updateAlphabetFromBrand($brand, $currentLocale, false);
            }
        }

        $this->entityManager->flush();
    }

    public function handleBrandDeletion(Brand $brand): void
    {
        $firstLetter = $brand->getFirstLetter();
        $locale = $this->detectLocaleByLetter($firstLetter);
        if ($firstLetter && $brand->isPublished()) {
            $this->decrementBrandCount($firstLetter, $locale);
            $this->entityManager->flush();
        }
    }

    private function decrementBrandCount(string $letter, string $locale): void
    {
        $alphabet = $this->alphabetRepository->findOneByLetterAndLocale($letter, $locale);

        if ($alphabet) {
            $alphabet->decrementBrandsCount();

            if ($alphabet->getBrandsCount() === 0) {
                $this->entityManager->remove($alphabet);
            } else {
                $this->entityManager->persist($alphabet);
            }
        }
    }

    public function getAlphabetData(string $locale): array
    {
        $availableLetters = $this->alphabetRepository->getAlphabetWithCounts($locale);
        $fullAlphabet = $this->getFullAlphabetForLocale($locale);

        $result = [];
        foreach ($fullAlphabet as $letter) {
            $found = array_filter($availableLetters, fn($item) => $item['letter'] === $letter);
            $brandsCount = $found ? current($found)['brandsCount'] : 0;

            $result[] = [
                'letter' => $letter,
                'available' => $brandsCount > 0,
                'brandsCount' => $brandsCount,
                'display' => true
            ];
        }

        return $result;
    }

    private function getFullAlphabetForLocale(string $locale): array
    {
        $alphabets = [
            'ru' => array_merge(range('А', 'Я'), ['.level', '#', '0-9']),
            'en' => array_merge(range('A', 'Z'), ['.level', '#', '0-9']),
        ];

        return $alphabets[$locale] ?? $alphabets['ru'];
    }

    /**
     * Реинициализация всего алфавита для локали
     */
    public function rebuildAlphabet(string $locale): void
    {
        // Удаляем существующие записи для локали
        $existing = $this->alphabetRepository->findBy(['locale' => $locale]);
        foreach ($existing as $item) {
            $this->entityManager->remove($item);
        }

        // Пересоздаем из активных брендов (все бренды, без привязки к локали)
        $brands = $this->entityManager->getRepository(Brand::class)
            ->findBy(['status' => 1]); // Используем статус из трейта

        foreach ($brands as $brand) {
            $this->updateAlphabetFromBrand($brand, $locale, true);
        }

        $this->entityManager->flush();
    }

    public function detectLocaleByLetter($letter): string
    {
//        if (empty($letter)) return 'empty';

        // Русский алфавит
        if (preg_match('/^[а-яё]/iu', $letter)) {
            return 'ru';
        }
        // Английский алфавит
        elseif (preg_match('/^[a-z]/i', $letter)) {
            return 'en';
        }
        // Цифры
        elseif (preg_match('/^[0-9]/', $letter)) {
            return 'digit';
        }
        // Спецсимволы
        elseif (preg_match('/^[^\w\s]/u', $letter)) {
            return 'special';
        }
        // Пробельные символы
        elseif (preg_match('/^\s/', $letter)) {
            return 'whitespace';
        }
        else {
            return 'other';
        }
    }
}
