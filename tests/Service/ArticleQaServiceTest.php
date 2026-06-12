<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArticleQaService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ArticleQaServiceTest extends TestCase
{
    private function service(bool $enabled = true): ArticleQaService
    {
        return new ArticleQaService(\dirname(__DIR__, 2), new NullLogger(), $enabled);
    }

    public function testDisabledGatePassesWithoutCheck(): void
    {
        $verdict = $this->service(enabled: false)->check('любой текст');

        $this->assertTrue($verdict['passed']);
        $this->assertFalse($verdict['checked']);
    }

    public function testGoodDescriptionPasses(): void
    {
        // Реалистичное описание бренда: конкретика, цифры, нормальный ритм.
        $text = <<<TXT
        Бренд «Северный шов» появился в Петербурге в 2018 году, когда дизайнер Анна Ковалёва
        сшила первую партию из двадцати парок на арендованном производстве на Лиговском проспекте.
        Сегодня марка выпускает четыре коллекции в год и работает с тремя фабриками в Ленинградской
        области. Основу ассортимента составляют утеплённые парки из мембранной ткани, шерстяные
        пальто и стёганые жилеты. Каждое изделие отшивается партиями до трёхсот единиц, поэтому
        модели редко повторяются. Фурнитуру бренд закупает у итальянской Riri, утеплитель —
        российский, на основе полиэфирного волокна. Цены держатся в среднем сегменте: парка стоит
        от 18 до 26 тысяч рублей, пальто — от 22 тысяч. Заказы отправляются со склада в Петербурге
        в течение двух рабочих дней, примерка при курьерской доставке доступна в Москве и Петербурге.
        Возврат принимается в течение четырнадцати дней. Постоянные покупатели получают доступ к
        закрытым предпродажам новых коллекций, о которых бренд сообщает в рассылке. В 2024 году
        марка открыла первый офлайн-корнер в универмаге «Телеграф» и планирует выход в Казань.
        TXT;

        $verdict = $this->service()->check($text);

        if (!$verdict['checked']) {
            $this->markTestSkipped('python3/тулкит недоступны — гейт в fail-open');
        }
        $this->assertTrue($verdict['passed'], 'Причины: ' . implode('; ', $verdict['reasons']));
        $this->assertGreaterThanOrEqual(75.0, $verdict['metrics']['overall']);
    }

    public function testAiSloppyTextFails(): void
    {
        // Концентрат LLM-штампов из ai_cliches_ru.txt — Human-likeness обязан просесть.
        $sentence = 'В современном быстро меняющемся мире стоит отметить, что данный бренд '
            . 'играет ключевую роль и является неотъемлемой частью индустрии, и ни для кого '
            . 'не секрет, что важно понимать, что качество трудно переоценить. ';
        $text = str_repeat($sentence, 12);

        $verdict = $this->service()->check($text);

        if (!$verdict['checked']) {
            $this->markTestSkipped('python3/тулкит недоступны — гейт в fail-open');
        }
        $this->assertFalse($verdict['passed']);
        $this->assertNotEmpty($verdict['reasons']);
    }
}
