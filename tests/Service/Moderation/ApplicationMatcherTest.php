<?php

declare(strict_types=1);

namespace App\Tests\Service\Moderation;

use App\Service\Moderation\ApplicationMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты детерминированного матчера (без LLM/сети). Фикстуры — по мотивам
 * реального малого бренда ahsilk.ru: сайт честно указывает свою почту на yandex.ru
 * (обычное дело для небольшого магазина) — это НЕ признак сквоттера, поэтому
 * identity_match подтверждается (телефон+email на сайте), а control_proof — нет
 * (домен email владельца не совпадает с доменом сайта).
 */
class ApplicationMatcherTest extends TestCase
{
    private ApplicationMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new ApplicationMatcher();
    }

    private const AHSILK_HTML = <<<'HTML'
        <html>
        <head><title>AH Silk — шёлковая одежда российского производства</title></head>
        <body>
        <h1>Ah Silk shopping</h1>
        <div>
        <p>Телефон: <a href="tel:+79686146174">+7 968 614-61-74</a></p>
        <p>Email: <a href="mailto:ah.silk@yandex.ru">ah.silk@yandex.ru</a></p>
        <p>Адрес: г. Новокузнецк, ул. Павловского, д. 11А, оф. 102</p>
        </div>
        </body>
        </html>
        HTML;

    public function testConfirmedIdentityButUnconfirmedControlWhenOwnerEmailDomainDiffers(): void
    {
        $brand = [
            'title'   => 'Ah Silk',
            'email'   => 'ah.silk@yandex.ru',
            'phone'   => '+7 968 614 6174',
            'address' => 'Новокузнецк, Павловского 11А, офис 102',
        ];
        $pages = [['url' => 'https://ahsilk.ru/', 'html' => self::AHSILK_HTML]];

        $result = $this->matcher->evaluate($brand, $pages, 'ahsilk.ru', 'ah.silk@yandex.ru');

        $this->assertSame('confirmed', $result['identity_match']);
        $this->assertSame('unconfirmed', $result['control_proof']); // yandex.ru ≠ ahsilk.ru
        $this->assertTrue($result['evidence'][0]['matched']['phone']);
        $this->assertTrue($result['evidence'][0]['matched']['email']);
        $this->assertTrue($result['evidence'][0]['matched']['address']);
    }

    public function testControlProofConfirmedWhenOwnerEmailMatchesSiteDomain(): void
    {
        $brand = ['title' => 'Ah Silk', 'phone' => '+7 968 614 6174'];
        $pages = [['url' => 'https://ahsilk.ru/', 'html' => self::AHSILK_HTML]];

        $result = $this->matcher->evaluate($brand, $pages, 'ahsilk.ru', 'owner@ahsilk.ru');

        $this->assertSame('confirmed', $result['control_proof']);
    }

    public function testWeakWhenOnlyOneStrongSignalAndNoTitleMatch(): void
    {
        $html = <<<'HTML'
            <html><head><title>Витрина товаров</title></head>
            <body><p>Тел: <a href="tel:+79161234567">+7 916 123-45-67</a></p></body></html>
            HTML;
        $brand = ['title' => 'Совершенно Другое Имя', 'phone' => '+7 916 123 4567'];

        $result = $this->matcher->evaluate($brand, [['url' => 'https://example.ru/', 'html' => $html]], 'example.ru', null);

        $this->assertSame('weak', $result['identity_match']);
    }

    public function testConfirmedWithOneStrongSignalPlusTitleMatch(): void
    {
        $html = <<<'HTML'
            <html><head><title>Магазин Совершенно Другое Имя — одежда</title></head>
            <body><p>Тел: <a href="tel:+79161234567">+7 916 123-45-67</a></p></body></html>
            HTML;
        $brand = ['title' => 'Совершенно Другое Имя', 'phone' => '+7 916 123 4567'];

        $result = $this->matcher->evaluate($brand, [['url' => 'https://example.ru/', 'html' => $html]], 'example.ru', null);

        $this->assertSame('confirmed', $result['identity_match']);
    }

    public function testUnconfirmedForSquatterWithForeignContactsEvenIfTitleMentioned(): void
    {
        // Сквоттер: страница ПРО бренд заявителя (название совпадает), но реальные контакты — чужие.
        $html = <<<'HTML'
            <html><head><title>Всё про Наш Бренд — обзор конкурентов</title></head>
            <body><p>Тел: <a href="tel:+79997776655">+7 999 777-66-55</a></p>
            <p>Email: <a href="mailto:other@rival.ru">other@rival.ru</a></p></body></html>
            HTML;
        $brand = ['title' => 'Наш Бренд', 'phone' => '+7 111 222 3344', 'email' => 'owner@ourbrand.ru'];

        $result = $this->matcher->evaluate($brand, [['url' => 'https://squatter.example/', 'html' => $html]], 'squatter.example', 'owner@ourbrand.ru');

        $this->assertSame('unconfirmed', $result['identity_match']);
        $this->assertSame('unconfirmed', $result['control_proof']);
    }

    public function testNoTraceWhenNoCandidateSiteFound(): void
    {
        $result = $this->matcher->evaluate(['title' => 'Любой Бренд'], [], null, 'owner@example.ru');

        $this->assertSame('no_trace', $result['identity_match']);
        $this->assertSame('unconfirmed', $result['control_proof']);
        $this->assertSame([], $result['evidence']);
    }

    public function testNormalizePhoneStripsLeadingCountryDigit(): void
    {
        $this->assertSame('9686146174', $this->matcher->normalizePhone('+7 968 614 6174'));
        $this->assertSame('9686146174', $this->matcher->normalizePhone('89686146174'));
        $this->assertSame('9686146174', $this->matcher->normalizePhone('9686146174'));
    }
}
