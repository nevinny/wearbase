<?php

namespace App\Service;

use App\Repository\BrandRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class BrandService
{
    private const CACHE_TTL = 3600; // 1 hour
    private const ALPHABET_CACHE_KEY = 'brands_alphabet_';
    private const BRANDS_BY_LETTER_CACHE_KEY = 'brands_by_letter_';

    public function __construct(
        private BrandRepository $brandRepository,
        private CacheInterface $cache
    ) {}

    public function getAlphabetData(string $locale, ?string $selectedLetter = null): array
    {
        $cacheKey = self::ALPHABET_CACHE_KEY . $locale;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($locale) {
            $item->expiresAfter(self::CACHE_TTL);

            $alphabet = $this->getAlphabetForLocale($locale);
            $availableLetters = $this->brandRepository->findAvailableFirstLetters($locale);

            return $this->enrichAlphabetWithAvailability($alphabet, $availableLetters);
        });
    }

    public function getBrandsByLetter(string $locale, ?string $letter = null): array
    {
        if (!$letter) {
            return $this->brandRepository->findAllActiveBrands($locale);
        }

        $cacheKey = self::BRANDS_BY_LETTER_CACHE_KEY . $locale . '_' . $letter;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($locale, $letter) {
            $item->expiresAfter(self::CACHE_TTL);
            return $this->brandRepository->findByFirstLetter($letter, $locale);
        });
    }

    private function getAlphabetForLocale(string $locale): array
    {
        $alphabets = [
            'ru' => $this->getRussianAlphabet(),
            'en' => $this->getEnglishAlphabet(),
        ];

        return $alphabets[$locale] ?? $alphabets['en'];
    }

    private function getRussianAlphabet(): array
    {
        $baseAlphabet = range('А', 'Я');

        // Добавляем специальные символы
        $specialChars = ['.level', '#', '0-9'];

        return array_merge($baseAlphabet, $specialChars);
    }

    private function getEnglishAlphabet(): array
    {
        $baseAlphabet = range('A', 'Z');

        // Добавляем специальные символы
        $specialChars = ['.level', '#', '0-9'];

        return array_merge($baseAlphabet, $specialChars);
    }

    private function enrichAlphabetWithAvailability(array $alphabet, array $availableLetters): array
    {
        $result = [];

        foreach ($alphabet as $letter) {
            $normalizedLetter = $this->normalizeLetter($letter);
            $isAvailable = in_array($normalizedLetter, $availableLetters);

            $result[] = [
                'letter' => $letter,
                'normalized' => $normalizedLetter,
                'available' => $isAvailable,
                'display' => $this->shouldDisplayLetter($letter, $isAvailable)
            ];
        }

        return $result;
    }

    private function normalizeLetter(string $letter): string
    {
        // Нормализуем специальные символы для поиска в БД
        $mapping = [
            '.level' => '.',
            '0-9' => '0-9',
            '#' => '#'
        ];

        return $mapping[$letter] ?? mb_strtoupper($letter);
    }

    private function shouldDisplayLetter(string $letter, bool $isAvailable): bool
    {
        // Всегда показываем буквы, даже если для них нет записей
        // Можно изменить на return $isAvailable; чтобы скрывать пустые
        return true;
    }
}
