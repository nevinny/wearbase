<?php

namespace App\Service\Social;

use App\Entity\SocialPost;
use App\Service\LlmService;

/**
 * Сборка подписи поста. Тон — честный/дерзкий (docs/marketing_strategy.md §5), без sterile-копии.
 *
 * Ядро-сообщения (template-рубрики) берутся из фиксированного банка ротации — без LLM,
 * ноль галлюцинаций, on-message. Брендовые (llm-рубрики) генерятся из описания бренда.
 * Структурные части (CTA-ссылка, хэштеги) добавляются детерминированно кодом.
 */
class CaptionGenerator
{
    /** Банк ядра-сообщений (ротация по индексу). Источник — пиллары marketing_strategy.md §5. */
    private const BANK = [
        'manifesto' => [
            "Мы не строчки в чужой выдаче. Мы не отдаём половину выручки за право показать вам свою вещь.\nМы знаем своих покупателей по имени. Мы — прямые бренды.",
            "На Wildberries ты — артикул. У нас ты — бренд.\nРусские марки, которые продают напрямую, а не арендуют полку у маркетплейса.",
            "Маркетплейс сдаёт бренду его же покупателей в аренду — и берёт за это половину выручки.\nПрямой бренд оставляет себе и клиентов, и маржу, и имя.",
        ],
        'calculator' => [
            "Маркетплейс берёт с бренда 30–67% с каждой продажи. Мы — 0%.\nПри обороте 300 000 ₽/мес площадка забрала бы до 150 000 ₽. Прямой бренд платит фикс и оставляет это себе.",
            "3000 ₽ в месяц или 50% с каждой продажи. Считать умеешь?\nФикс не растёт вместе с твоим оборотом — в отличие от комиссии маркетплейса.",
            "Сколько Wildberries забирает лично у твоего бренда? Посчитай за 10 секунд.\nЧаще всего это десятки тысяч рублей в месяц, которые могли остаться у тебя.",
        ],
        'vs_marketplace' => [
            "Та же вещь, что у перекупа на маркетплейсе дороже. Только тут — напрямую от бренда.\nБез посредников, без подделок, деньги идут тому, кто это сшил.",
            "Миф: «на маркетплейсе дешевле». Реальность: цену поднимает комиссия и перекупы.\nУ бренда напрямую — честная цена и оригинал.",
            "Маркетплейс продаёт «куртку женскую оверсайз». Бренд продаёт себя.\nПокупая напрямую, ты выбираешь марку, а не строчку в выдаче алгоритма.",
        ],
        'ugc' => [
            "Бренды-участники носят бейдж «Прямой бренд». Ищите его — это знак, что марка продаёт вам напрямую.",
        ],
    ];

    public function __construct(
        private readonly LlmService $llm,
        private readonly string $siteBaseUrl,
    ) {
    }

    /**
     * Собрать полную подпись поста: тело (банк или LLM) + CTA + хэштеги.
     * Возвращает готовую строку; aiGenerated проставляет вызывающий по источнику рубрики.
     */
    public function compose(SocialPost $post, array $rubricDef): string
    {
        $body = $rubricDef['source'] === SocialRubrics::SOURCE_LLM
            ? $this->llmBody($post)
            : $this->templateBody($post->getRubric(), (int) $post->getId());

        return $this->assemble($body, $post, $rubricDef['hashtags']);
    }

    /** Детерминированный выбор из банка (ротация по id поста — стабильно при ретраях). */
    private function templateBody(string $rubric, int $seed): string
    {
        $bank = self::BANK[$rubric] ?? self::BANK['manifesto'];

        return $bank[$seed % count($bank)];
    }

    /** Подпись брендовой рубрики из описания бренда (grounded, без выдумок). */
    private function llmBody(SocialPost $post): string
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

    /** Тело + CTA-ссылка + хэштеги. */
    private function assemble(string $body, SocialPost $post, array $hashtags): string
    {
        $cta = $this->ctaLine($post);
        $tags = implode(' ', $hashtags);

        return trim($body) . "\n\n" . $cta . "\n\n" . $tags;
    }

    private function ctaLine(SocialPost $post): string
    {
        $source = $post->getChannel()?->getPlatform() ?? 'social';
        $brand = $post->getBrand();

        if ($brand !== null && $brand->getSlug()) {
            return 'Бренд напрямую → ' . $this->withUtm('/ru/brands/' . $brand->getSlug(), $source, $post->getRubric());
        }

        return 'Каталог независимых русских брендов → ' . $this->withUtm('/ru/', $source, $post->getRubric());
    }

    /** Ссылка с UTM-метками — для отслеживания эффективности канала/рубрики в аналитике. */
    private function withUtm(string $path, string $source, string $rubric): string
    {
        $query = http_build_query([
            'utm_source'   => $source,                          // tg | vk | ig
            'utm_medium'   => 'social',
            'utm_campaign' => $rubric !== '' ? $rubric : 'social_auto',
        ]);

        return rtrim($this->siteBaseUrl, '/') . $path . '?' . $query;
    }
}
