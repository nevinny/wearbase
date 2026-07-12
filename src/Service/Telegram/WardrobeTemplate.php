<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Entity\WardrobeItem;

/**
 * Шаблон и парсер Telegram-ввода вещи гардероба. Чистый класс без зависимостей.
 *
 * Драфт — JSON-совместимый массив (хранится в telegram_dialog_state.draft):
 * category, name, size, price (строка-decimal), purchased_at ('Y-m-d'),
 * product_url, purchase_reason, love (yes|no|unknown), photo_file_id.
 */
class WardrobeTemplate
{
    public const TEMPLATE = <<<TPL
        Категория:
        Название:
        Размер:
        Стоимость:
        Дата покупки:
        Ссылка:
        Задача покупки:
        Любовь с первого взгляда: Да / Нет / Пока не знаю
        TPL;

    // Нормализованный ключ строки шаблона → ключ драфта
    private const KEY_MAP = [
        'категория'                => 'category',
        'название'                 => 'name',
        'вещь'                     => 'name',
        'размер'                   => 'size',
        'стоимость'                => 'price',
        'цена'                     => 'price',
        'дата покупки'             => 'purchased_at',
        'дата'                     => 'purchased_at',
        'ссылка'                   => 'product_url',
        'url'                      => 'product_url',
        'задача покупки'           => 'purchase_reason',
        'причина покупки'          => 'purchase_reason',
        'причина'                  => 'purchase_reason',
        'зачем'                    => 'purchase_reason',
        'любовь с первого взгляда' => 'love',
        'любовь'                   => 'love',
    ];

    private const REQUIRED = [
        'category' => 'Категория',
        'name'     => 'Название',
    ];

    private const LOVE_LABELS = [
        WardrobeItem::LOVE_YES     => 'Да',
        WardrobeItem::LOVE_NO      => 'Нет',
        WardrobeItem::LOVE_UNKNOWN => 'Пока не знаю',
    ];

    /**
     * Толерантный построчный парсер «Ключ: значение».
     * Пустые/невалидные значения пропускаются (ключ в результат не попадает).
     *
     * @return array<string, string>
     */
    public function parse(string $text): array
    {
        $draft = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $key = null;
            $value = '';
            if (str_contains($line, ':')) {
                [$rawKey, $value] = explode(':', $line, 2);
                // Эмодзи, маркеры списков, цифры — вычищаем, оставляем буквы
                $normKey = mb_strtolower(trim((string) preg_replace(['/[^\p{L}\s]/u', '/\s+/u'], ['', ' '], $rawKey)));
                $key = self::KEY_MAP[$normKey] ?? null;
                $value = trim($value);
            }

            if ($key === null) {
                // Голый URL строкой без ключа
                if (preg_match('~https?://\S+~u', $line, $m)) {
                    $draft['product_url'] = $m[0];
                }
                continue;
            }

            if ($value === '' || $value === 'Да / Нет / Пока не знаю') {
                continue; // незаполненная строка шаблона
            }

            $parsed = $this->parseValue($key, $value);
            if ($parsed !== null) {
                $draft[$key] = $parsed;
            }
        }

        return $draft;
    }

    private function parseValue(string $key, string $value): ?string
    {
        switch ($key) {
            case 'price':
                // «891 ₽», «1 200 руб», «1200,50» → строка-decimal
                if (!preg_match('/\d[\d\s\x{00A0}\x{202F}]*(?:[.,]\d{1,2})?/u', $value, $m)) {
                    return null;
                }
                $num = str_replace(',', '.', (string) preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $m[0]));
                return $num === '' ? null : $num;

            case 'purchased_at':
                foreach (['d.m.Y', 'Y-m-d'] as $format) {
                    $dt = \DateTimeImmutable::createFromFormat('!' . $format, $value);
                    if ($dt !== false) {
                        return $dt->format('Y-m-d');
                    }
                }
                return null; // невалидная дата — пропускаем

            case 'product_url':
                return preg_match('~https?://\S+~u', $value, $m) ? $m[0] : $value;

            case 'love':
                $v = mb_strtolower(trim($value, " \t.!…"));
                if (preg_match('/не\s*знаю/u', $v)) {
                    return WardrobeItem::LOVE_UNKNOWN;
                }
                if (preg_match('/^да\b/u', $v)) {
                    return WardrobeItem::LOVE_YES;
                }
                if (preg_match('/^нет\b/u', $v)) {
                    return WardrobeItem::LOVE_NO;
                }
                return null;

            default:
                return $value;
        }
    }

    /**
     * Человекочитаемые названия недостающих обязательных полей.
     *
     * @param array<string, mixed> $draft
     * @return string[] напр. ['Категория', 'Название']
     */
    public function missingRequired(array $draft): array
    {
        $missing = [];
        foreach (self::REQUIRED as $key => $label) {
            if (trim((string) ($draft[$key] ?? '')) === '') {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    /** Карточка созданной вещи (parse_mode HTML). */
    public function formatCard(WardrobeItem $item): string
    {
        $lines = [sprintf('✅ Вещь <b>%s</b> добавлена в гардероб', $item->getDisplayNumber()), ''];

        $fields = [
            'Категория'     => $item->getCategory(),
            'Название'      => $item->getName(),
            'Размер'        => $item->getSize(),
            'Стоимость'     => $item->getPrice() !== null ? $this->formatMoney((float) $item->getPrice()) : null,
            'Дата покупки'  => $item->getPurchasedAt()?->format('d.m.Y'),
            'Ссылка'        => $item->getProductUrl(),
            'Задача покупки' => $item->getPurchaseReason(),
            'Любовь с первого взгляда' => $item->getLoveAtFirstSight() !== null
                ? (self::LOVE_LABELS[$item->getLoveAtFirstSight()] ?? $item->getLoveAtFirstSight())
                : null,
        ];
        foreach ($fields as $label => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = sprintf('%s: %s', $label, htmlspecialchars($value));
            }
        }

        return implode("\n", $lines);
    }

    /** Карточка ЧЕРНОВИКА (до коммита) — превью после AI-разбора фото (parse_mode HTML). */
    public function formatDraftCard(array $draft): string
    {
        $lines = ['📝 <b>Черновик вещи</b>', ''];

        $fields = [
            'Категория'     => $draft['category'] ?? null,
            'Название'      => $draft['name'] ?? null,
            'Размер'        => $draft['size'] ?? null,
            'Стоимость'     => isset($draft['price']) ? $this->formatMoney((float) $draft['price']) : null,
            'Дата покупки'  => isset($draft['purchased_at']) ? $this->formatDraftDate((string) $draft['purchased_at']) : null,
            'Ссылка'        => $draft['product_url'] ?? null,
            'Задача покупки' => $draft['purchase_reason'] ?? null,
            'Заметки'       => $draft['notes'] ?? null,
            'Любовь с первого взгляда' => isset($draft['love'])
                ? (self::LOVE_LABELS[$draft['love']] ?? $draft['love'])
                : null,
        ];
        foreach ($fields as $label => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = sprintf('%s: %s', $label, htmlspecialchars((string) $value));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Шаблон с уже собранными в драфте значениями (кнопка «✏️ Дополнить»): пользователь
     * дописывает/правит нужные строки и отправляет — обычный parse() смёржит их в draft.
     */
    public function prefilled(array $draft): string
    {
        $love = isset($draft['love'])
            ? (self::LOVE_LABELS[$draft['love']] ?? 'Да / Нет / Пока не знаю')
            : 'Да / Нет / Пока не знаю';

        $lines = [
            'Категория: ' . ($draft['category'] ?? ''),
            'Название: ' . ($draft['name'] ?? ''),
            'Размер: ' . ($draft['size'] ?? ''),
            'Стоимость: ' . (isset($draft['price']) ? (string) $draft['price'] : ''),
            'Дата покупки: ' . (isset($draft['purchased_at']) ? $this->formatDraftDate((string) $draft['purchased_at']) : ''),
            'Ссылка: ' . ($draft['product_url'] ?? ''),
            'Задача покупки: ' . ($draft['purchase_reason'] ?? ''),
            'Любовь с первого взгляда: ' . $love,
        ];

        return "✏️ Поправьте нужные строки и отправьте:\n\n" . htmlspecialchars(implode("\n", $lines));
    }

    /** 'Y-m-d' (формат драфта) → 'd.m.Y' (формат отображения); невалидную строку возвращает как есть. */
    private function formatDraftDate(string $ymd): string
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        return $dt !== false ? $dt->format('d.m.Y') : $ymd;
    }

    /**
     * Статистика гардероба (parse_mode HTML).
     *
     * @param array<int, array{category: string, cnt: int|string, total: string}> $stats из WardrobeItemRepository::getStats()
     */
    public function formatStats(array $stats, int $totalCount, float $totalSum): string
    {
        $lines = [sprintf('📊 <b>Ваш гардероб:</b> %d %s на %s',
            $totalCount,
            $this->pluralItems($totalCount),
            $this->formatMoney($totalSum),
        )];
        foreach ($stats as $row) {
            $lines[] = sprintf('• %s — %d', htmlspecialchars($row['category']), (int) $row['cnt']);
        }

        return implode("\n", $lines);
    }

    /** Пустой шаблон для следующей вещи (parse_mode HTML). */
    public function blankTemplate(): string
    {
        return "📷 Следующая вещь — фото с подписью:\n\n" . htmlspecialchars(self::TEMPLATE);
    }

    /** Инструкция по /wardrobe (parse_mode HTML). */
    public function instruction(): string
    {
        return "📷 Пришлите фото вещи, а в подписи к нему — заполненный шаблон:\n\n"
            . htmlspecialchars(self::TEMPLATE)
            . "\n\nОбязательные — «Категория» и «Название», остальное можно пропустить."
            . " Фото и текст можно прислать отдельными сообщениями. /cancel — сбросить черновик.";
    }

    private function formatMoney(float $amount): string
    {
        $formatted = number_format($amount, 2, ',', ' ');
        return str_ends_with($formatted, ',00') ? substr($formatted, 0, -3) . ' ₽' : $formatted . ' ₽';
    }

    private function pluralItems(int $n): string
    {
        $mod100 = $n % 100;
        $mod10 = $n % 10;
        if ($mod100 >= 11 && $mod100 <= 14) {
            return 'вещей';
        }
        return match (true) {
            $mod10 === 1 => 'вещь',
            $mod10 >= 2 && $mod10 <= 4 => 'вещи',
            default => 'вещей',
        };
    }
}
