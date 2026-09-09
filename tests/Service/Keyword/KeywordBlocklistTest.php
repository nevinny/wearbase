<?php

declare(strict_types=1);

namespace App\Tests\Service\Keyword;

use App\Service\Keyword\KeywordBlocklist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Минус-слова ключевиков. Главное тут — НЕ ложные срабатывания: матчинг по токенам,
 * потому что подстрока режет легитимную моду (unisex→sex, nudeshop→nude,
 * сливочный→слив, слива→слив, betsy→bet).
 *
 * Run: php bin/phpunit tests/Service/Keyword/KeywordBlocklistTest.php
 */
class KeywordBlocklistTest extends TestCase
{
    /** Реальные фразы с прода, которые доехали до публичных карточек. */
    #[DataProvider('junkProvider')]
    public function testBlocksJunk(string $phrase): void
    {
        $this->assertTrue((new KeywordBlocklist())->isBlocked($phrase), $phrase);
    }

    public static function junkProvider(): iterable
    {
        yield ['murka onlyfans'];
        yield ['murka onlyfans порно'];
        yield ['murka anal'];
        yield ['murka porn'];
        yield ['murka nudes'];
        yield ['alena bevza порно'];
        yield ['anna montana порно'];
        yield ['julia iva porno'];
        yield ['atme слив'];
        yield ['atme me слив'];
        yield ['badgirl sex'];
        yield ['murka xxx'];
        yield ['nerdy banzo видео слив'];
        yield ['buff скачать бесплатно'];
        yield ['murka bet'];
        yield ['free tg murka proxy'];
        yield ['behind the scenes only fans'];
        yield ['murka webcam'];
        yield ['murka эротика'];
    }

    /** Легитимные fashion-фразы: ни одна не должна отсеиваться. */
    #[DataProvider('cleanProvider')]
    public function testKeepsLegitimateFashionPhrases(string $phrase): void
    {
        $this->assertFalse((new KeywordBlocklist())->isBlocked($phrase), $phrase);
    }

    public static function cleanProvider(): iterable
    {
        yield ['александр мурка'];
        yield ['murka одежда'];
        yield ['unisex одежда'];                 // содержит sex подстрокой
        yield ['nudeshop бренд'];                // содержит nude подстрокой
        yield ['nude костюм'];                   // легитимный цвет
        yield ['сливочный оверсайз'];            // содержит слив подстрокой
        yield ['платье цвета слива'];            // содержит слив подстрокой
        yield ['betsy певица'];                  // содержит bet подстрокой
        yield ['худи оверсайз купить'];
        yield ['российский бренд одежды'];
        yield ['лимитированные дропы'];
        yield ['стритвир бренд москва'];
        yield ['платье с голой спиной'];         // «голая» отдельно не блокируем
        yield ['модный casual'];
        yield [''];
    }

    /**
     * Осознанный компромисс: одиночное `nude` НЕ блокируем — в моде это цвет
     * («nude костюм», бренд Nudeshop). Цена — «murka nude» проскочит; ловим
     * форму множественного числа `nudes`, она однозначна.
     */
    public function testBareNudeStaysAllowedByDesign(): void
    {
        $blocklist = new KeywordBlocklist();

        $this->assertFalse($blocklist->isBlocked('murka nude'));
        $this->assertTrue($blocklist->isBlocked('murka nudes'));
    }

    public function testMatchReturnsTriggeredStopword(): void
    {
        $blocklist = new KeywordBlocklist();

        $this->assertSame('onlyfans', $blocklist->match('murka onlyfans'));
        $this->assertSame('порн*', $blocklist->match('alena bevza порно'));
        $this->assertNull($blocklist->match('murka одежда'));
    }

    /** Список правится без деплоя: KEYWORD_STOPWORDS добавляет токены к базовым. */
    public function testEnvStopwordsExtendTheList(): void
    {
        $blocklist = new KeywordBlocklist('телеграм, реплика');

        $this->assertTrue($blocklist->isBlocked('murka реплика'));
        $this->assertSame('реплика', $blocklist->match('murka реплика'));
        $this->assertFalse((new KeywordBlocklist())->isBlocked('murka реплика'));
    }
}
