<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DzenFeedController;
use PHPUnit\Framework\TestCase;

/**
 * Unit-тест приватного toDzenHtml() (dzen.ru/help/ru/website/rss-modify.html):
 * Дзен принимает только фиксированный набор тегов и не описывает атрибуты вовсе,
 * поэтому фид обязан отдавать разметку без id/class/style и без сторонних тегов.
 */
class DzenFeedControllerTest extends TestCase
{
    private function toDzenHtml(string $html): string
    {
        $controller = new DzenFeedController();
        $method = new \ReflectionMethod(DzenFeedController::class, 'toDzenHtml');
        $method->setAccessible(true);

        return $method->invoke($controller, $html);
    }

    public function testStripsAttributesAndUnwrapsDisallowedTags(): void
    {
        $html = '<h2 id="x">A</h2><p class="y" style="z">B<span>C</span></p>';

        $out = $this->toDzenHtml($html);

        $this->assertSame('<h2>A</h2><p>BC</p>', $out);
    }

    public function testKeepsHrefSrcAlt(): void
    {
        $html = '<p><a href="https://wearbase.ru" target="_blank" rel="noopener">link</a>'
            . '<img src="/x.jpg" alt="alt text" loading="lazy"></p>';

        $out = $this->toDzenHtml($html);

        $this->assertSame(
            '<p><a href="https://wearbase.ru">link</a><img src="/x.jpg" alt="alt text"></p>',
            $out,
        );
    }
}
