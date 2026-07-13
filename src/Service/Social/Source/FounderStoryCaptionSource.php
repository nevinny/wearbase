<?php

declare(strict_types=1);

namespace App\Service\Social\Source;

use App\Entity\SocialPost;
use App\Service\BrandRagService;
use App\Service\LlmService;
use App\Service\Social\SocialRubrics;

/**
 * brand_week: история бренда из RAG-фактов (BrandRagService) — почему/кем создан, что живо
 * сегодня, а не пересказ описания. Gate качества (chunks≥3 И score≥0.5) уже внутри
 * BrandRagService::retrieve(); Qdrant на LLM-сервере с непостоянным IP → любой вызов в try/catch,
 * gate не пройден/сервис недоступен → фолбэк на BrandDescriptionCaptionSource.
 */
class FounderStoryCaptionSource implements CaptionSourceInterface
{
    public function __construct(
        private readonly LlmService $llm,
        private readonly BrandRagService $rag,
        private readonly BrandDescriptionCaptionSource $fallback,
    ) {
    }

    public function key(): string
    {
        return SocialRubrics::SOURCE_FOUNDER_STORY;
    }

    public function body(SocialPost $post): string
    {
        $brand = $post->getBrand();
        if ($brand === null) {
            throw new \RuntimeException('Рубрика истории основателя без бренда: ' . $post->getRubric());
        }

        try {
            $context = $this->rag->retrieve($brand)['context'] ?? null;
        } catch (\Throwable) {
            $context = null;
        }

        if ($context === null) {
            return $this->fallback->body($post);
        }

        $name = (string) $brand->getTitle();
        $system = 'Ты — SMM-копирайтер. Пишешь по-русски, живо и по делу, без рекламных штампов и без markdown. '
            . 'Отвечаешь только текстом подписи.';
        $prompt = <<<EOT
Выдержки из реальных публикаций о бренде «{$name}» (единственный источник фактов):

{$context}

Напиши подпись-историю: почему и кем создан бренд «{$name}», что в нём живое сегодня.
3–4 коротких предложения, максимум 60 слов, на «ты», без перечисления дат.
Запрещено: выдумывать факты сверх приведённых, «инновационный», «уникальный», «передовой», «лидирующий», кавычки, markdown, ссылки, хэштеги.
Только текст подписи.
EOT;

        return trim($this->llm->generate($prompt, $system, local: true, think: false));
    }
}
