<?php

declare(strict_types=1);

namespace App\Service\Social\Source;

use App\Entity\SocialPost;
use App\Service\LlmService;
use App\Service\Social\SocialRubrics;

/** Брендовые рубрики: подпись генерится из описания бренда (grounded, без выдумок). */
class BrandDescriptionCaptionSource implements CaptionSourceInterface
{
    public function __construct(
        private readonly LlmService $llm,
    ) {
    }

    public function key(): string
    {
        return SocialRubrics::SOURCE_LLM;
    }

    public function body(SocialPost $post): string
    {
        $brand = $post->getBrand();
        if ($brand === null) {
            throw new \RuntimeException('Брендовая рубрика без бренда: ' . $post->getRubric());
        }

        $name = (string) $brand->getTitle();
        $city = $brand->getCity();
        $desc = trim((string) ($brand->getDescription() ?: $brand->getAnons()));
        if ($desc === '') {
            throw new \RuntimeException("У бренда «{$name}» нет описания для подписи");
        }

        $cityCtx = $city ? " из города {$city}" : '';
        $system = 'Ты — SMM-копирайтер. Пишешь по-русски, живо и по делу, без рекламных штампов и без markdown. '
            . 'Отвечаешь только текстом подписи.';
        $prompt = <<<EOT
Бренд одежды «{$name}»{$cityCtx}. Описание (единственный источник фактов):

{$desc}

Напиши подпись для поста в соцсети о ЭТОМ бренде: 2–3 коротких предложения, максимум 45 слов.
Зацепи интересом «нашёл марку раньше всех», говори о бренде по сути из описания.
Запрещено: «инновационный», «уникальный», «передовой», «лидирующий», «выделяется», кавычки, markdown, ссылки, хэштеги.
Только текст подписи.
EOT;

        return trim($this->llm->generate($prompt, $system, local: true, think: false));
    }
}
