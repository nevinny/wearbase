<?php

namespace App\Tests\Service;

use App\Service\ContentValidator;
use PHPUnit\Framework\TestCase;

class ContentValidatorTest extends TestCase
{
    private ContentValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ContentValidator();
    }

    /** Скобки-плейсхолдеры от LLM обязаны резаться. */
    public function testPlaceholderBracketsCaught(): void
    {
        $placeholders = [
            '[название бренда]',
            '[город]',
            '[услуги/товары]',
            '[вставьте описание]',
            '[укажите год]',
            '[brand name]',
            '[N]',
            '[1]',
            '[...]',
            '{описание}',
        ];

        foreach ($placeholders as $placeholder) {
            $errors = $this->validator->validateDescription($this->longText($placeholder));
            $this->assertNotEmpty(
                array_filter($errors, fn ($e) => str_contains($e, 'placeholder') || str_contains($e, 'скобки')),
                "Плейсхолдер {$placeholder} должен ловиться, errors: " . implode('; ', $errors)
            );
        }
    }

    /** Фонетические транскрипции с сайтов брендов — легитимные скобки, не резать
     *  (кейс Roshi #4973: «РОШ[И']» → 200+ ложных generate_failed). */
    public function testPhoneticBracketsAllowed(): void
    {
        $legit = [
            "Произносится как РОШ[И'], ударение на последний слог.",
            'Название читается как [эла́пс].',
            'Бренд Юлии Широкой, произносится как [ЮЛ].',
        ];

        foreach ($legit as $fragment) {
            $errors = $this->validator->validateDescription($this->longText($fragment));
            $this->assertEmpty(
                array_filter($errors, fn ($e) => str_contains($e, 'placeholder') || str_contains($e, 'скобки')),
                "Легитимные скобки «{$fragment}» не должны ловиться, errors: " . implode('; ', $errors)
            );
        }
    }

    /** Дополняет фрагмент до минимума слов, чтобы не ловить ошибку «мало слов». */
    private function longText(string $fragment): string
    {
        return $fragment . ' ' . str_repeat('Бренд выпускает одежду из натуральных тканей в Москве. ', 25);
    }
}
