<?php

declare(strict_types=1);

namespace App\Tests\Service\Telegram;

use App\Entity\WardrobeItem;
use App\Service\Telegram\WardrobeTemplate;
use PHPUnit\Framework\TestCase;

class WardrobeTemplateTest extends TestCase
{
    private WardrobeTemplate $template;

    protected function setUp(): void
    {
        $this->template = new WardrobeTemplate();
    }

    public function testParseFullTemplateAllFields(): void
    {
        $text = <<<TXT
            Категория: Худи
            Название: Худи Nike Tech Fleece
            Размер: M
            Стоимость: 891 ₽
            Дата покупки: 17.05.2024
            Ссылка: https://example.com/item/1
            Задача покупки: Тепло на осень
            Любовь с первого взгляда: Да
            TXT;

        $draft = $this->template->parse($text);

        self::assertSame([
            'category' => 'Худи',
            'name' => 'Худи Nike Tech Fleece',
            'size' => 'M',
            'price' => '891',
            'purchased_at' => '2024-05-17',
            'product_url' => 'https://example.com/item/1',
            'purchase_reason' => 'Тепло на осень',
            'love' => WardrobeItem::LOVE_YES,
        ], $draft);
    }

    public function testParseSynonymPriceStoimostVsTsena(): void
    {
        $viaStoimost = $this->template->parse('Стоимость: 500');
        $viaTsena = $this->template->parse('Цена: 500');

        self::assertSame('500', $viaStoimost['price']);
        self::assertSame('500', $viaTsena['price']);
    }

    public function testParseSynonymNameNazvanieVsVeshch(): void
    {
        $viaNazvanie = $this->template->parse('Название: Куртка');
        $viaVeshch = $this->template->parse('Вещь: Куртка');

        self::assertSame('Куртка', $viaNazvanie['name']);
        self::assertSame('Куртка', $viaVeshch['name']);
    }

    public function testParseSynonymPurchaseReasonPrichinaVsZadacha(): void
    {
        $viaZadacha = $this->template->parse('Задача покупки: На работу');
        $viaPrichina = $this->template->parse('Причина: На работу');

        self::assertSame('На работу', $viaZadacha['purchase_reason']);
        self::assertSame('На работу', $viaPrichina['purchase_reason']);
    }

    public function testParseSynonymLoveVsLoveFullPhrase(): void
    {
        $viaShort = $this->template->parse('Любовь: Да');
        $viaFull = $this->template->parse('Любовь с первого взгляда: Да');

        self::assertSame(WardrobeItem::LOVE_YES, $viaShort['love']);
        self::assertSame(WardrobeItem::LOVE_YES, $viaFull['love']);
    }

    public function testParsePriceFormats(): void
    {
        self::assertSame('891', $this->template->parse('Цена: 891 ₽')['price']);
        self::assertSame('1200', $this->template->parse('Цена: 1 200 руб')['price']);
        self::assertSame('12300', $this->template->parse('Цена: 12300')['price']);
    }

    public function testParsePriceGarbageIgnored(): void
    {
        $draft = $this->template->parse('Цена: дорого');

        self::assertArrayNotHasKey('price', $draft);
    }

    public function testParseDateFormats(): void
    {
        self::assertSame('2024-05-17', $this->template->parse('Дата покупки: 17.05.2024')['purchased_at']);
        self::assertSame('2024-05-17', $this->template->parse('Дата покупки: 2024-05-17')['purchased_at']);
    }

    public function testParseDateInvalidIgnored(): void
    {
        $draft = $this->template->parse('Дата покупки: когда-то в мае');

        self::assertArrayNotHasKey('purchased_at', $draft);
    }

    public function testParseLoveVariants(): void
    {
        self::assertSame(WardrobeItem::LOVE_YES, $this->template->parse('Любовь: Да')['love']);
        self::assertSame(WardrobeItem::LOVE_NO, $this->template->parse('Любовь: Нет')['love']);
        self::assertSame(WardrobeItem::LOVE_UNKNOWN, $this->template->parse('Любовь: Пока не знаю')['love']);
        self::assertSame(WardrobeItem::LOVE_UNKNOWN, $this->template->parse('Любовь: не знаю')['love']);

        // Регистронезависимость значения
        self::assertSame(WardrobeItem::LOVE_YES, $this->template->parse('Любовь: ДА')['love']);
        self::assertSame(WardrobeItem::LOVE_NO, $this->template->parse('Любовь: НЕТ')['love']);
        self::assertSame(WardrobeItem::LOVE_UNKNOWN, $this->template->parse('Любовь: ПОКА НЕ ЗНАЮ')['love']);
    }

    public function testParseEmptyValueAfterColonSkipped(): void
    {
        $draft = $this->template->parse("Категория: Худи\nРазмер:\nСтоимость: \n");

        self::assertSame(['category' => 'Худи'], $draft);
    }

    public function testParseKeysCaseInsensitive(): void
    {
        $draft = $this->template->parse("КАТЕГОРИЯ: Худи\nнАзВаНие: Свитер");

        self::assertSame('Худи', $draft['category']);
        self::assertSame('Свитер', $draft['name']);
    }

    public function testParseMarkersAndEmojiInKeyDoNotBreakParsing(): void
    {
        $draft = $this->template->parse("📦 Категория: Худи\n1. Название: Свитер\n- Цена: 500");

        self::assertSame('Худи', $draft['category']);
        self::assertSame('Свитер', $draft['name']);
        self::assertSame('500', $draft['price']);
    }

    public function testParseBareUrlLine(): void
    {
        $draft = $this->template->parse('https://wildberries.ru/catalog/12345/detail.aspx');

        self::assertSame('https://wildberries.ru/catalog/12345/detail.aspx', $draft['product_url']);
    }

    public function testParseTextWithoutKeyValuePairsIsEmpty(): void
    {
        $draft = $this->template->parse("Привет! Вот моя новая крутая вещь, купил вчера в магазине.");

        self::assertSame([], $draft);
    }

    public function testMissingRequiredEmptyDraft(): void
    {
        self::assertSame(['Категория', 'Название'], $this->template->missingRequired([]));
    }

    public function testMissingRequiredOnlyCategory(): void
    {
        self::assertSame(['Название'], $this->template->missingRequired(['category' => 'Худи']));
    }

    public function testMissingRequiredComplete(): void
    {
        self::assertSame([], $this->template->missingRequired(['category' => 'Худи', 'name' => 'Худи Nike']));
    }

    public function testBlankTemplateContainsCategory(): void
    {
        $text = $this->template->blankTemplate();

        self::assertNotSame('', $text);
        self::assertStringContainsString('Категория', $text);
    }

    public function testInstructionContainsCategory(): void
    {
        $text = $this->template->instruction();

        self::assertNotSame('', $text);
        self::assertStringContainsString('Категория', $text);
    }

    public function testFormatStatsContainsCategoriesAndSum(): void
    {
        $stats = [
            ['category' => 'Худи', 'cnt' => 3, 'total' => '1500.00'],
            ['category' => 'Кроссовки', 'cnt' => 1, 'total' => '3000.00'],
        ];

        $result = $this->template->formatStats($stats, 4, 4500.0);

        self::assertStringContainsString('Худи — 3', $result);
        self::assertStringContainsString('Кроссовки — 1', $result);
        self::assertStringContainsString('4 вещи', $result);
        self::assertStringContainsString('4 500', $result);
        self::assertStringContainsString('₽', $result);
    }

    public function testFormatDraftCardShowsCollectedFieldsOnly(): void
    {
        $draft = [
            'category' => 'Худи',
            'name'     => 'Худи Nike Tech Fleece',
            'price'    => '891',
            'notes'    => 'Цвет: чёрный',
        ];

        $card = $this->template->formatDraftCard($draft);

        self::assertStringContainsString('📝', $card);
        self::assertStringContainsString('Категория: Худи', $card);
        self::assertStringContainsString('Название: Худи Nike Tech Fleece', $card);
        self::assertStringContainsString('891', $card);
        self::assertStringContainsString('Заметки: Цвет: чёрный', $card);
        self::assertStringNotContainsString('Ссылка', $card);
        self::assertStringNotContainsString('Любовь', $card);
    }

    public function testFormatDraftCardFormatsPurchasedAtAndLove(): void
    {
        $draft = [
            'category'     => 'Худи',
            'name'         => 'Худи',
            'purchased_at' => '2024-05-17',
            'love'         => WardrobeItem::LOVE_YES,
        ];

        $card = $this->template->formatDraftCard($draft);

        self::assertStringContainsString('Дата покупки: 17.05.2024', $card);
        self::assertStringContainsString('Любовь с первого взгляда: Да', $card);
    }

    public function testPrefilledContainsCollectedValuesAndLovePlaceholderWhenUnset(): void
    {
        $draft = ['category' => 'Худи', 'name' => 'Худи Nike'];

        $text = $this->template->prefilled($draft);

        self::assertStringContainsString('Категория: Худи', $text);
        self::assertStringContainsString('Название: Худи Nike', $text);
        self::assertStringContainsString('Да / Нет / Пока не знаю', $text);
    }

    public function testPrefilledShowsLoveLabelWhenAlreadySet(): void
    {
        $draft = ['category' => 'Худи', 'name' => 'Худи', 'love' => WardrobeItem::LOVE_NO];

        $text = $this->template->prefilled($draft);

        self::assertStringContainsString('Любовь с первого взгляда: Нет', $text);
        self::assertStringNotContainsString('Да / Нет / Пока не знаю', $text);
    }

    public function testFormatCard(): void
    {
        $item = new WardrobeItem();
        $item->setItemNo(7);
        $item->setCategory('Худи');
        $item->setName('Худи Nike');
        $item->setSize('M');
        $item->setPrice('891.00');
        $item->setPurchasedAt(new \DateTimeImmutable('2024-05-17'));
        $item->setProductUrl('https://example.com/item/1');
        $item->setPurchaseReason('Тепло');
        $item->setLoveAtFirstSight(WardrobeItem::LOVE_YES);

        $card = $this->template->formatCard($item);

        self::assertStringContainsString('#0007', $card);
        self::assertStringContainsString('Категория: Худи', $card);
        self::assertStringContainsString('Название: Худи Nike', $card);
        self::assertStringContainsString('Любовь с первого взгляда: Да', $card);
    }
}
