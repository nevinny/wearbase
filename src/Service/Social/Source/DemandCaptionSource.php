<?php

declare(strict_types=1);

namespace App\Service\Social\Source;

use App\Entity\SocialPost;
use App\Repository\BrandKeywordRepository;
use App\Service\LlmService;
use App\Service\Social\SocialRubrics;

/**
 * demand (вт): ответ на реальный поисковый спрос — топ-фраза бренда из brand_keyword
 * (Wordstat, ORDER BY monthlyShows DESC). Двойная польза: соцсети + GEO/SEO (та же
 * фраза, что уже приведена в трафик на сайт). Нет ключевиков → фолбэк на описание.
 */
class DemandCaptionSource implements CaptionSourceInterface
{
    public function __construct(
        private readonly LlmService $llm,
        private readonly BrandKeywordRepository $keywords,
        private readonly BrandDescriptionCaptionSource $fallback,
    ) {
    }

    public function key(): string
    {
        return SocialRubrics::SOURCE_DEMAND;
    }

    public function body(SocialPost $post): string
    {
        $brand = $post->getBrand();
        if ($brand === null) {
            throw new \RuntimeException('Рубрика demand без бренда: ' . $post->getRubric());
        }

        $top = $this->keywords->findTopByBrand($brand);
        if ($top === null) {
            return $this->fallback->body($post);
        }

        $desc = trim((string) ($brand->getDescription() ?: $brand->getAnons()));
        if ($desc === '') {
            return $this->fallback->body($post); // сам выбросит, если и там пусто
        }

        $name = (string) $brand->getTitle();
        $phrase = $top->getKeyword();
        $shows = $top->getMonthlyShows() ?? 0;

        $system = 'Ты — SMM-копирайтер. Пишешь по-русски, живо и по делу, без рекламных штампов и без markdown. '
            . 'Отвечаешь только текстом подписи.';
        $prompt = <<<EOT
Люди ищут в Яндексе «{$phrase}» ({$shows} показов/мес). Описание бренда (единственный источник фактов):

{$desc}

Напиши подпись-ответ на этот запрос: почему этому запросу отвечает бренд {$name}.
2–4 предложения, максимум 50 слов, на «ты».
Запрещено: выдумывать факты сверх описания, «инновационный», «уникальный», «передовой», «лидирующий», «выделяется», кавычки, markdown, ссылки, хэштеги.
Только текст подписи.
EOT;

        return trim($this->llm->generate($prompt, $system, local: true, think: false));
    }
}
